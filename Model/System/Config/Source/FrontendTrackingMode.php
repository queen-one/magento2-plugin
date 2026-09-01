<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FrontendTrackingMode implements OptionSourceInterface
{
    public const REJOINER = 'rejoiner';
    public const DUAL = 'dual';
    public const QUEEN_ONE = 'queen_one';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::REJOINER, 'label' => __('Rejoiner')],
            ['value' => self::DUAL, 'label' => __('Rejoiner + Queen One (shadow)')],
            ['value' => self::QUEEN_ONE, 'label' => __('Queen One')],
        ];
    }
}
