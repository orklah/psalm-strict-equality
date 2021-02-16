<?php declare(strict_types=1);

namespace Orklah\StrictEquality\Hooks;

use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Scalar;
use Psalm\FileManipulation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic;


class StrictEqualityHooks implements AfterExpressionAnalysisInterface
{

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $node_provider = $event->getStatementsSource()->getNodeTypeProvider();
        if (!$expr instanceof Equal) {
            return true;
        }

        $left_type = $node_provider->getType($expr->left);
        $right_type = $node_provider->getType($expr->right);

        //if (!$expr->left instanceof Scalar && !$expr->right instanceof Scalar) {
        //    //toggle for allowing only when one element is a scala
        //    return true;
        //}

        if ($left_type === null || $right_type === null) {
            return true;
        }

        if ($left_type->from_docblock || $right_type->from_docblock) {
            return true;// this is risky
        }

        if (!$left_type->isSingle() || !$right_type->isSingle()) {
            return true; // may be refined later
        }

        $left_type_atomics = $left_type->getAtomicTypes();
        $right_type_atomics = $right_type->getAtomicTypes();

        $left_type_single = array_pop($left_type_atomics);
        $right_type_single = array_pop($right_type_atomics);

        if (self::isCompatibleType($left_type_single, $right_type_single)) {
            $startPos = $expr->left->getEndFilePos() + 1;
            $endPos = $expr->right->getStartFilePos();
            $length = $endPos - $startPos;
            if ($length >= 2 && $length <= 4) {
                $file_manipulation = new FileManipulation($startPos, $endPos, ' === ');
                $event->setFileReplacements([$file_manipulation]);
            }
        }

        //solve more cases, for examples, numeric-string vs string
        //Double TLiteral string, check direct?
        return true;
    }

    private static function isCompatibleType(Atomic $left_type_single, Atomic $right_type_single): bool
    {
        //This is just a trick to avoid handling every way
        return self::isCompatibleTypeOrdered($left_type_single, $right_type_single) || self::isCompatibleTypeOrdered($right_type_single, $left_type_single);
    }

    private static function isCompatibleTypeOrdered(Atomic $first_type, Atomic $second_type){
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

        if ($first_type instanceof Atomic\TArray && $second_type instanceof Atomic\TArray) {
            return true;
        }

        if ($first_type instanceof Atomic\TKeyedArray && $second_type instanceof Atomic\TKeyedArray) {
            return true;
        }

        if ($first_type instanceof Atomic\TKeyedArray && $second_type instanceof Atomic\TArray) {
            return true;
        }

        return false;
    }
}
