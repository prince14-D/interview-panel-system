<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use PDO;
use RuntimeException;

/**
 * Virtual Interview WebRTC Service (Jitsi Meet External API & LiveKit Server SDK JWT).
 * Strictly scoped to interview_mode = 'virtual'.
 * Video recording is disabled by default and requires explicit authorization + consent.
 */
final class VideoConferenceService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createOrUpdateRoom(
        int $sessionId,
        string $provider,
        ?string $serverDomain,
        bool $waitingRoomEnabled,
        int $actorId
    ): array {
        if (!in_array($provider, ['jitsi', 'livekit'], true)) {
            throw new RuntimeException('Unsupported video provider. Choose Jitsi or LiveKit.');
        }

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $videoCfg = $config['video'] ?? [];

        $effectiveDomain = trim((string)$serverDomain);
        if ($effectiveDomain === '') {
            $effectiveDomain = $provider === 'livekit'
                ? ($videoCfg['livekit_ws_url'] ?? '')
                : ($videoCfg['jitsi_domain'] ?? 'meet.jit.si');
        }

        $existing = $this->getRoomBySession($sessionId);
        if ($existing) {
            $upd = $this->pdo->prepare(
                'UPDATE video_interview_rooms
                 SET provider = :provider,
                     server_domain = :domain,
                     waiting_room_enabled = :waiting,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE session_id = :sid'
            );
            $upd->execute([
                'provider' => $provider,
                'domain' => $effectiveDomain,
                'waiting' => $waitingRoomEnabled ? 1 : 0,
                'sid' => $sessionId,
            ]);
            return $this->getRoomBySession($sessionId);
        }

        $roomName = 'MYS-LR-' . $sessionId . '-' . strtoupper(bin2hex(random_bytes(4)));
        $passcode = strtoupper(bin2hex(random_bytes(3)));
        $panelistToken = bin2hex(random_bytes(24));

        $ins = $this->pdo->prepare(
            'INSERT INTO video_interview_rooms
                (session_id, provider, server_domain, room_name, room_passcode, panelist_access_token,
                 waiting_room_enabled, recording_enabled, recording_consent_required, meeting_status)
             VALUES
                (:sid, :provider, :domain, :room_name, :passcode, :ptoken, :waiting, 0, 1, \'scheduled\')'
        );
        $ins->execute([
            'sid' => $sessionId,
            'provider' => $provider,
            'domain' => $effectiveDomain,
            'room_name' => $roomName,
            'passcode' => $passcode,
            'ptoken' => $panelistToken,
            'waiting' => $waitingRoomEnabled ? 1 : 0,
        ]);

        AuditLogger::log($this->pdo, $actorId, 'video_room_created', 'video_interview_room', $sessionId, "Provider: {$provider}, Room: {$roomName}");
        return $this->getRoomBySession($sessionId);
    }

    public function getRoomBySession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_interview_rooms WHERE session_id = :sid LIMIT 1');
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function ensureCandidateInviteToken(int $sessionId, int $candidateId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT virtual_invite_token FROM session_candidates WHERE session_id = :sid AND candidate_id = :cid LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(20));
        $upd = $this->pdo->prepare(
            'UPDATE session_candidates SET virtual_invite_token = :token WHERE session_id = :sid AND candidate_id = :cid'
        );
        $upd->execute(['token' => $token, 'sid' => $sessionId, 'cid' => $candidateId]);
        return $token;
    }

    public function updateMeetingStatus(int $sessionId, string $status, int $actorId): void
    {
        if (!in_array($status, ['scheduled', 'waiting', 'live', 'ended'], true)) {
            throw new RuntimeException('Invalid virtual meeting status.');
        }

        $extra = '';
        if ($status === 'live') {
            $extra = ', started_at = COALESCE(started_at, CURRENT_TIMESTAMP)';
        } elseif ($status === 'ended') {
            $extra = ', ended_at = CURRENT_TIMESTAMP';
        }

        $stmt = $this->pdo->prepare(
            "UPDATE video_interview_rooms SET meeting_status = :status{$extra}, updated_at = CURRENT_TIMESTAMP WHERE session_id = :sid"
        );
        $stmt->execute(['status' => $status, 'sid' => $sessionId]);

        if ($status === 'live') {
            $this->pdo->prepare("UPDATE interview_sessions SET status = 'in_progress' WHERE id = :sid AND status = 'scheduled'")
                ->execute(['sid' => $sessionId]);
        }

        AuditLogger::log($this->pdo, $actorId, 'virtual_meeting_status', 'video_interview_room', $sessionId, "Meeting status -> {$status}");
    }

    /**
     * Explicit administrator authorization required to enable video recording (disabled by default).
     */
    public function setRecordingAuthorization(int $sessionId, bool $enable, int $adminUserId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE video_interview_rooms
             SET recording_enabled = :enabled,
                 recording_consent_required = 1,
                 recording_authorized_by = :uid,
                 recording_authorized_at = CASE WHEN :enabled2 = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = :sid'
        );
        $stmt->execute([
            'enabled' => $enable ? 1 : 0,
            'enabled2' => $enable ? 1 : 0,
            'uid' => $adminUserId,
            'sid' => $sessionId,
        ]);

        AuditLogger::log(
            $this->pdo,
            $adminUserId,
            $enable ? 'recording_authorized' : 'recording_disabled',
            'video_interview_room',
            $sessionId,
            $enable ? 'Recording authorized (consent required)' : 'Recording disabled'
        );
    }

    public function admitCandidateFromWaitingRoom(int $sessionId, int $candidateId, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE session_candidates
             SET waiting_room_admitted = 1,
                 interview_status = 'in_progress',
                 interview_started_at = COALESCE(interview_started_at, CURRENT_TIMESTAMP),
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = :sid AND candidate_id = :cid"
        );
        $stmt->execute(['sid' => $sessionId, 'cid' => $candidateId]);

        AuditLogger::log($this->pdo, $actorId, 'waiting_room_admitted', 'session_candidate', $candidateId, "Admitted to virtual room in session #{$sessionId}");
    }

    /**
     * Generate a LiveKit-compliant HS256 JWT Access Token (zero external dependency).
     */
    public function generateLiveKitToken(
        string $roomName,
        string $identity,
        string $displayName,
        bool $isModerator = false,
        int $ttlSeconds = 7200
    ): ?string {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $apiKey = $config['video']['livekit_api_key'] ?? '';
        $apiSecret = $config['video']['livekit_api_secret'] ?? '';

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $apiKey,
            'sub' => $identity,
            'name' => $displayName,
            'nbf' => $now - 10,
            'exp' => $now + $ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
            'video' => [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
                'roomAdmin' => $isModerator,
            ],
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $apiSecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
