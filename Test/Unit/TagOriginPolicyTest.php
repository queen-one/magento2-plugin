<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use Magento\Csp\Model\Collector\FetchPolicyMerger;
use Magento\Csp\Model\CompositePolicyCollector;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;
use Rejoiner\Acr\Model\TagOriginPolicy;
use Rejoiner\Acr\Model\TrackingConfig;

class TagOriginPolicyTest extends TestCase
{
    public function testAddsOnlyTheConfiguredOriginAndPreservesRestrictivePolicy(): void
    {
        $original = new FetchPolicy(
            'script-src', false, ['https://existing.example'], [], true, false, false,
            ['existing-nonce'], ['existing-hash' => 'sha256']
        );
        $collector = new CompositePolicyCollector([$this->collector()], [new FetchPolicyMerger()]);
        $result = $collector->collect([$original])[0];

        self::assertSame(['https://existing.example', 'https://tag.example:8443'], $result->getHostSources());
        self::assertTrue($result->isSelfAllowed());
        self::assertFalse($result->isInlineAllowed());
        self::assertFalse($result->isEvalAllowed());
        self::assertSame($original->getNonceValues(), $result->getNonceValues());
        self::assertSame($original->getHashes(), $result->getHashes());
    }

    public function testDisabledTrackingLeavesPoliciesUntouched(): void
    {
        $policies = [new FetchPolicy('default-src', false, [], [], true)];
        self::assertSame($policies, $this->collector('frontend', false)->collect($policies));
    }

    public function testOtherAreasLeavePoliciesUntouched(): void
    {
        $policies = [new FetchPolicy('script-src', false, [], [], true)];
        foreach (['adminhtml', 'webapi_rest', 'crontab'] as $area) {
            self::assertSame($policies, $this->collector($area)->collect($policies));
        }
    }

    public function testConfigurationFailureLeavesPoliciesUntouched(): void
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('isEnabled')->willThrowException(new \RuntimeException('Configuration unavailable'));
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $policies = [new FetchPolicy('default-src', false, [], [], true)];

        self::assertSame($policies, (new TagOriginPolicy($config, $state))->collect($policies));
    }

    private function collector(string $area = 'frontend', bool $enabled = true): TagOriginPolicy
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getTagUrl')->willReturn('https://tag.example:8443/queen-one.js?version=1');
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        return new TagOriginPolicy($config, $state);
    }
}
