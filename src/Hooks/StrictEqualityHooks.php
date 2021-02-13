<?php declare(strict_types=1);

namespace Orklah\StrictEquality\Hooks;

use PhpParser\Node\Expr\BinaryOp\Equal;
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
            $startPos = $expr->left->getEndFilePos();
            $endPos = $expr->right->getStartFilePos();
            $length = $endPos - $startPos;
            if($length >= 2 && $length <= 4) {
                $file_manipulation = new FileManipulation($expr->left->getEndFilePos() + 1, $expr->right->getStartFilePos(), ' === ');
                $event->setFileReplacements([$file_manipulation]);
            }
        }

        //solve more cases, for examples, numeric-string vs string
        //Double TLiteral string, check direct?
        return true;
    }

    private static function isCompatibleType(Atomic $left_type_single, Atomic $right_type_single): bool
    {
        if ($left_type_single instanceof Atomic\TString && $right_type_single instanceof Atomic\TString) {
            return true;
        }

        if ($left_type_single instanceof Atomic\TInt && $right_type_single instanceof Atomic\TInt) {
            return true;
        }

        if ($left_type_single instanceof Atomic\TFloat && $right_type_single instanceof Atomic\TFloat) {
            return true;
        }

        if ($left_type_single instanceof Atomic\TBool && $right_type_single instanceof Atomic\TBool) {
            return true;
        }

        if ($left_type_single instanceof Atomic\TArray && $right_type_single instanceof Atomic\TArray) {
            return true;
        }

        if ($left_type_single instanceof Atomic\TKeyedArray && $right_type_single instanceof Atomic\TKeyedArray) {
            return true;
        }

        //KeyedArray vs Array
        return false;
    }

}
