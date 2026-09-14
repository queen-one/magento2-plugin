<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Rejoiner\Acr\Model\TrackingConfig;

class TrackingConfigTest extends TestCase
{
    public function testOnlyCompleteConnectConfigurationEnablesTracking(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);
        $scope->method('getValue')->willReturnCallback(fn ($path) => match ($path) {
            'checkout/queen_one_connect/site_id' => ' site ',
            'checkout/queen_one_connect/tag_url' => ' https://tag.example/queen-one.js ',
            default => throw new \RuntimeException('Unexpected legacy configuration lookup')
        });
        self::assertTrue((new TrackingConfig($scope))->isEnabled());
    }

    public function testUnsafeOrIncompleteTagUrlsAreRejected(): void
    {
        foreach (['', 'http://tag.example/a.js', 'javascript:alert(1)', 'https://user:pass@tag.example/a.js',
            'https://tag.example/a.js#fragment'] as $url) {
            self::assertFalse(TrackingConfig::isValidTagUrl($url));
        }
        self::assertTrue(TrackingConfig::isValidTagUrl('https://tag.example/queen-one.js?pr=1'));
    }
}
