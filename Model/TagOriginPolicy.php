<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Throwable;

class TagOriginPolicy implements PolicyCollectorInterface
{
    private const ENVIRONMENT_ORIGINS = [
        'queen-one-init.queen.one' => [
            'script-src' => [
                'https://queen-one-init.queen.one',
                'https://queen-one-core.queen.one',
                'https://cdn.queen.one'
            ],
            'connect-src' => ['https://events.queen.one', 'https://cdn.queen.one'],
            'style-src' => ['https://frames-cdn.queen.one']
        ],
        'queen-one-init-staging.queen-one.workers.dev' => [
            'script-src' => [
                'https://queen-one-init-staging.queen-one.workers.dev',
                'https://queen-one-core-staging.queen.one',
                'https://cdn.queen.one'
            ],
            'connect-src' => ['https://events.staging.queen.one', 'https://cdn.queen.one'],
            'style-src' => ['https://queen-one-frames-staging.queen.one']
        ]
    ];

    public function __construct(
        private TrackingConfig $config,
        private State $state
    ) {
    }

    public function collect(array $defaultPolicies = []): array
    {
        try {
            if ($this->state->getAreaCode() === Area::AREA_FRONTEND && $this->config->isEnabled()) {
                $url = parse_url($this->config->getTagUrl());
                $host = strtolower((string) $url['host']);
                $origin = 'https://' . $host . (isset($url['port']) ? ':' . $url['port'] : '');
                $policies = self::ENVIRONMENT_ORIGINS[$host] ?? ['script-src' => [$origin]];
                foreach ($policies as $policy => $origins) {
                    $defaultPolicies[] = new FetchPolicy($policy, false, $origins);
                }
            }
        } catch (Throwable $error) {
            // Invalid tracking configuration must not break CSP header generation.
        }
        return $defaultPolicies;
    }
}
