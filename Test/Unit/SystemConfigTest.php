<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use PHPUnit\Framework\TestCase;

class SystemConfigTest extends TestCase
{
    public function testProductionServiceDefaultsAreDeclared(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml');
        self::assertNotFalse($config);
        self::assertSame(
            'https://queen-one-init.queen.one/queen-one.js',
            (string) $config->default->checkout->queen_one_connect->tag_url
        );
        self::assertSame(
            'https://integrations.queen.one',
            (string) $config->default->checkout->queen_one_connect->bridge_url
        );
    }

    public function testServiceEndpointsAreHiddenAndHmacSecretIsEncrypted(): void
    {
        $root = dirname(__DIR__, 2);
        $system = simplexml_load_file($root . '/etc/adminhtml/system.xml');
        self::assertNotFalse($system);

        $fields = $system->xpath('/config/system/section[@id="checkout"]/group[@id="queen_one_connect"]/field');
        self::assertIsArray($fields);
        $byId = [];
        foreach ($fields as $field) {
            $byId[(string) $field['id']] = $field;
        }

        self::assertArrayNotHasKey('tag_url', $byId);
        self::assertArrayNotHasKey('bridge_url', $byId);
        self::assertSame('obscure', (string) $byId['hmac_secret']['type']);
        self::assertSame(
            'Magento\\Config\\Model\\Config\\Backend\\Encrypted',
            (string) $byId['hmac_secret']->backend_model
        );

        $di = simplexml_load_file($root . '/etc/di.xml');
        self::assertNotFalse($di);
        $sensitive = $di->xpath(
            '/config/type[@name="Magento\\Config\\Model\\Config\\TypePool"]'
            . '/arguments/argument[@name="sensitive"]/item[@name="checkout/queen_one_connect/hmac_secret"]'
        );
        self::assertCount(1, $sensitive);
    }
}
