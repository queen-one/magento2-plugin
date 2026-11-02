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
    public function testAddsUnknownConfiguredOriginAndPreservesRestrictivePolicy(): void
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

    public function testAddsExactProductionDependencies(): void
    {
        $policies = (new CompositePolicyCollector(
            [$this->collector('frontend', true, 'https://queen-one-init.queen.one/queen-one.js')],
            [new FetchPolicyMerger()]
        ))->collect([]);
        $byId = [];
        foreach ($policies as $policy) {
            $byId[$policy->getId()] = $policy->getHostSources();
            self::assertFalse($policy->isInlineAllowed());
            self::assertFalse($policy->isEvalAllowed());
        }

        self::assertSame([
            'https://queen-one-init.queen.one', 'https://queen-one-core.queen.one', 'https://cdn.queen.one'
        ], $byId['script-src']);
        self::assertSame(['https://events.queen.one', 'https://cdn.queen.one'], $byId['connect-src']);
        self::assertSame(['https://frames-cdn.queen.one'], $byId['style-src']);
    }

    public function testAddsExactStagingDependencies(): void
    {
        $policies = (new CompositePolicyCollector([
            $this->collector('frontend', true, 'https://queen-one-init-staging.queen-one.workers.dev/queen-one.js')
        ], [new FetchPolicyMerger()]))->collect([]);
        $byId = [];
        foreach ($policies as $policy) {
            $byId[$policy->getId()] = $policy->getHostSources();
        }

        self::assertSame([
            'https://queen-one-init-staging.queen-one.workers.dev',
            'https://queen-one-core-staging.queen.one',
            'https://cdn.queen.one'
        ], $byId['script-src']);
        self::assertSame(['https://events.staging.queen.one', 'https://cdn.queen.one'], $byId['connect-src']);
        self::assertSame(['https://queen-one-frames-staging.queen.one'], $byId['style-src']);
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

    private function collector(
        string $area = 'frontend',
        bool $enabled = true,
        string $tagUrl = 'https://tag.example:8443/queen-one.js?version=1'
    ): TagOriginPolicy
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getTagUrl')->willReturn($tagUrl);
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        return new TagOriginPolicy($config, $state);
    }
}
