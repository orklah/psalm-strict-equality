<?php declare(strict_types=1);

namespace Orklah\StrictEquality\Hooks;

use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\FileManipulation;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Node\VirtualNode;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic;
use function end;
use function get_class;

class StrictEqualityHooks implements AfterExpressionAnalysisInterface
{

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if ($expr instanceof VirtualNode) {
            // This is a node created by Psalm for analysis purposes. This is not interesting
            return true;
        }

        if (!$expr instanceof Equal && !$expr instanceof NotEqual) {
            return true;
        }

        $statements_source = $event->getStatementsSource();

        //start by emitting an issue that can be added into the baseline to avoid adding new ones
        $issue = new NotStrictEqualSign(
            'Using ' . $expr->getOperatorSigil() . ' is deprecated by orklah/psalm-strict-equality',
            new CodeLocation($statements_source, $expr)
        );
        IssueBuffer::accepts($issue, $statements_source->getSuppressedIssues());

        if (!$event->getCodebase()->alter_code) {
            return true;
        }

        $node_provider = $event->getStatementsSource()->getNodeTypeProvider();
        $left_type = $node_provider->getType($expr->left);
        $right_type = $node_provider->getType($expr->right);

        if ($left_type === null || $right_type === null) {
            return true;
        }

        if ($left_type->from_docblock || $right_type->from_docblock) {
            $config = Config::getInstance();
            foreach ($config->getPluginClasses() as $plugin) {
                if ($plugin['class'] != 'Orklah\StrictEquality\Plugin') {
                    continue;
                }

                if (!isset($plugin['config']->strictEqualityFromDocblock['value']) || (string) $plugin['config']->strictEqualityFromDocblock['value'] !== 'true') {
                    return true;
                }

                break;
            }
        }

        $left_type_atomics = $left_type->getAtomicTypes();
        $right_type_atomics = $right_type->getAtomicTypes();

        $expr_class = get_class($expr);
        if ($left_type->isSingle() && $right_type->isSingle()) {
            $left_type_single = end($left_type_atomics);
            $right_type_single = end($right_type_atomics);
            $fixable = self::isCompatibleType($left_type_single, $right_type_single, $expr_class);
        } else {
            $fixable = self::isUnionCompatibleType($left_type_atomics, $right_type_atomics, $expr_class);
        }

        if ($fixable === true) {
            $startPos = $expr->left->getEndFilePos() + 1;
            $endPos = $expr->right->getStartFilePos();

            $file_manipulation = new FileManipulation($startPos, $endPos, $expr_class === Equal::class ? ' === ' : ' !== ');
            $event->setFileReplacements([$file_manipulation]);
        }

        return true;
    }

    /**
     * @param class-string<Equal>|class-string<NotEqual> $expr_class
     */
    private static function isCompatibleType(Atomic $left_type_single, Atomic $right_type_single, string $expr_class): bool
    {
        if ($expr_class === Equal::class) {
            //This is just a trick to avoid handling every way
            return self::isEqualOrdered($left_type_single, $right_type_single) || self::isEqualOrdered($right_type_single, $left_type_single);
        } else {
            return self::isNotEqualOrdered($left_type_single, $right_type_single) || self::isNotEqualOrdered($right_type_single, $left_type_single);
        }
    }

    private static function isEqualOrdered(Atomic $first_type, Atomic $second_type): bool
    {
        if ($first_type instanceof Atomic\TString && $second_type instanceof Atomic\TString) {
            // oh god, I hate this: https://3v4l.org/O7RXC
            return true;
        }

        if ($first_type instanceof Atomic\TInt && $second_type instanceof Atomic\TInt) {
            return true;
        }

        if ($first_type instanceof Atomic\TFloat && $second_type instanceof Atomic\TFloat) {
            return true;
        }

        if ($first_type instanceof Atomic\TBool && $second_type instanceof Atomic\TBool) {
            return true;
        }

        // array/objects are somewhat safe to compare against strings
        if (self::isTooComplicatedType($first_type) && $second_type instanceof Atomic\TString) {
            return true;
        } elseif (self::isTooComplicatedType($first_type)) {
            return false;
        }

        // generic same or parent class
        if ($first_type instanceof $second_type) {
            return true;
        }

        return false;
    }

    private static function isNotEqualOrdered(Atomic $first_type, Atomic $second_type): bool
    {
        // identical at the moment
        return self::isEqualOrdered($first_type, $second_type);
    }

    private static function isUnionCompatibleType(array $left_type_atomics, array $right_type_atomics, string $expr_class): bool {
        if ($expr_class === Equal::class) {
            //This is just a trick to avoid handling every way
            return self::isUnionEqualOrdered($left_type_atomics, $right_type_atomics) || self::isUnionEqualOrdered($right_type_atomics, $left_type_atomics) ||
                   self::isUnionStringEqualOrdered($left_type_atomics, $right_type_atomics) || self::isUnionStringEqualOrdered($right_type_atomics, $left_type_atomics);
        } else {
            return self::isUnionNotEqualOrdered($left_type_atomics, $right_type_atomics) || self::isUnionNotEqualOrdered($right_type_atomics, $left_type_atomics) ||
                   self::isUnionStringNotEqualOrdered($left_type_atomics, $right_type_atomics) || self::isUnionStringNotEqualOrdered($right_type_atomics, $left_type_atomics);
        }
    }

    private static function isUnionStringEqualOrdered(array $first_types, array $second_types): bool
    {
        foreach ($first_types as $atomic_type) {
            if ($atomic_type instanceof Atomic\TNonEmptyString) {
                $with_null = true;
                continue;
            }

            if ($atomic_type instanceof Atomic\TString) {
                $with_null = false;
                continue;
            }

            return false;
        }

        foreach ($second_types as $atomic_type) {
            if ($atomic_type instanceof Atomic\TString) {
                continue;
            }

            if ($with_null === true && $atomic_type instanceof Atomic\TNull) {
                continue;
            }

            // array/objects are somewhat safe to compare against strings
            if (self::isTooComplicatedType($atomic_type)) {
                continue;
            }

            if ($atomic_type instanceof Atomic\TCallable) {
                continue;
            }

            if ($atomic_type instanceof Atomic\TResource) {
                continue;
            }

            if ($atomic_type instanceof Atomic\TClosedResource) {
                continue;
            }

            return false;
        }

        return true;
    }

    private static function isUnionStringNotEqualOrdered(array $first_types, array $second_types): bool
    {
        // identical at the moment
        return self::isUnionStringEqualOrdered($first_types, $second_types);
    }

    private static function isUnionEqualOrdered(array $first_types, array $second_types): bool {
        $top_level_class = false;
        foreach ($first_types as $atomic_type) {
            if ($top_level_class === false) {
                $top_level_class = $atomic_type;
                continue;
            }

            if ($atomic_type instanceof $top_level_class) {
                continue;
            }

            if ($top_level_class instanceof $atomic_type) {
                $top_level_class = $atomic_type;
                continue;
            }

            return false;
        }

        if (self::isTooComplicatedType($top_level_class)) {
            return false;
        }

        foreach ($second_types as $atomic_type) {
            if ($atomic_type instanceof $top_level_class) {
                continue;
            }

            if ($top_level_class instanceof $atomic_type) {
                $top_level_class = $atomic_type;
                continue;
            }

            return false;
        }

        return true;
    }

    private static function isUnionNotEqualOrdered(array $first_types, array $second_types): bool
    {
        // identical at the moment
        return self::isUnionEqualOrdered($first_types, $second_types);
    }

    private static function isTooComplicatedType(Atomic $type) {
        $too_complicated_types = array(
            Atomic\TKeyedArray::class,
            Atomic\TArray::class,
            Atomic\TList::class,
            Atomic\TIterable::class,
            Atomic\TNamedObject::class,
            Atomic\TObject::class,
        );

        foreach ($too_complicated_types as $compare) {
            if ($type instanceof $compare || $compare instanceof $type) {
                return true;
            }
        }

        return false;
    }
}

class NotStrictEqualSign extends PluginIssue
{
}
