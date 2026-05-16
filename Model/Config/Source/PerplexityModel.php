<?php
declare(strict_types=1);
namespace Angeo\AeoBrandVisibility\Model\Config\Source;
use Magento\Framework\Data\OptionSourceInterface;
class PerplexityModel implements OptionSourceInterface {
    public function toOptionArray(): array {
        return [
            ['value' => 'sonar',              'label' => 'Sonar — Live web search (Recommended)'],
            ['value' => 'sonar-pro',          'label' => 'Sonar Pro — Advanced live search'],
            ['value' => 'sonar-reasoning',    'label' => 'Sonar Reasoning — With chain-of-thought'],
            ['value' => 'sonar-reasoning-pro','label' => 'Sonar Reasoning Pro'],
            ['value' => 'sonar-deep-research','label' => 'Sonar Deep Research — Most thorough'],
        ];
    }
}
