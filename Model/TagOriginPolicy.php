<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Throwable;

class TagOriginPolicy implements PolicyCollectorInterface
{
    public function __construct(private TrackingConfig $config)
    {
    }

    public function collect(array $defaultPolicies = []): array
    {
        try {
            if ($this->config->isEnabled()) {
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
