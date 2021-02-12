<?php declare(strict_types=1);
namespace Orklah\StrictEquality;

use Orklah\StrictEquality\Hooks\StrictEqualityHooks;
use SimpleXMLElement;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        if(class_exists(StrictEqualityHooks::class)){
            $registration->registerHooksFromClass(StrictEqualityHooks::class);
        }
    }
}
