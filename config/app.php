<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'MYS Interview Panel & Scoring System'),
    'organization' => Env::get('APP_ORG', 'Ministry of Youth and Sports, Republic of Liberia'),
    'republic' => 'Republic of Liberia',
    'ministry' => 'Ministry of Youth and Sports',
    'address' => 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia, Liberia',
    'env' => Env::get('APP_ENV', 'local'),
    'debug' => Env::getBool('APP_DEBUG', false),
    'url' => rtrim(Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Monrovia'),
    'secret' => Env::get('APP_SECRET', 'mys-liberia-change-me-in-production'),

    'video' => [
        'default_provider' => Env::get('VIDEO_DEFAULT_PROVIDER', 'jitsi'),
        'jitsi_domain' => Env::get('JITSI_DOMAIN', 'meet.jit.si'),
        'jitsi_app_id' => Env::get('JITSI_APP_ID', ''),
        'jitsi_app_secret' => Env::get('JITSI_APP_SECRET', ''),
        'livekit_ws_url' => Env::get('LIVEKIT_WS_URL', ''),
        'livekit_api_key' => Env::get('LIVEKIT_API_KEY', ''),
        'livekit_api_secret' => Env::get('LIVEKIT_API_SECRET', ''),
    ],

    'default_criteria' => [
        [
            'criterion_name' => 'Educational Qualifications',
            'description' => 'Relevance and level of academic credentials, certifications, and formal training.',
            'max_marks' => 15.00,
            'display_order' => 1,
        ],
        [
            'criterion_name' => 'Technical Knowledge',
            'description' => 'Mastery of role-specific competencies, public administration standards, and technical skills.',
            'max_marks' => 25.00,
            'display_order' => 2,
        ],
        [
            'criterion_name' => 'Professional Experience',
            'description' => 'Depth and relevance of prior work experience, track record, and measurable achievements.',
            'max_marks' => 20.00,
            'display_order' => 3,
        ],
        [
            'criterion_name' => 'Communication Skills',
            'description' => 'Clarity of expression, active listening, articulation, and professional presentation.',
            'max_marks' => 15.00,
            'display_order' => 4,
        ],
        [
            'criterion_name' => 'Problem-Solving Ability',
            'description' => 'Analytical thinking, sound judgment, adaptability, and practical decision-making.',
            'max_marks' => 15.00,
            'display_order' => 5,
        ],
        [
            'criterion_name' => 'Leadership and Teamwork',
            'description' => 'Collaborative mindset, integrity, initiative, and capacity to lead or support teams.',
            'max_marks' => 10.00,
            'display_order' => 6,
        ],
    ],
];
