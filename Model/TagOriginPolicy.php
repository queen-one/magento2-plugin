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
                $origin = 'https://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
                $defaultPolicies[] = new FetchPolicy('script-src', false, [$origin]);
            }
        } catch (Throwable $error) {
            // Invalid tracking configuration must not break CSP header generation.
        }
        return $defaultPolicies;
    }
}
