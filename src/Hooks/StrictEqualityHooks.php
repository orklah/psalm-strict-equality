<?php declare(strict_types=1);

namespace Orklah\StrictTypes\Hooks;

use PhpParser\Node\Expr\BinaryOp\Equal;
use Psalm\FileManipulation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use function get_class;


class StrictEqualityHooks implements AfterExpressionAnalysisInterface
{

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $node_provider = $event->getStatementsSource()->getNodeTypeProvider();
        if(!$expr instanceof Equal){
            return true;
        }

        $left_type = $node_provider->getType($expr->left);
        $right_type = $node_provider->getType($expr->right);

        if($left_type === null || $right_type === null){
            return true;
        }

        if($left_type->from_docblock || $right_type->from_docblock){
            return true;// this is risky
        }

        if(!$left_type->isSingle() || !$right_type->isSingle()){
            return true; // may be refined later
        }

        $left_type_atomics = $left_type->getAtomicTypes();
        $right_type_atomics = $right_type->getAtomicTypes();

        $left_type_single = array_pop($left_type_atomics);
        $right_type_single = array_pop($right_type_atomics);

        if(get_class($left_type_single) === get_class($right_type_single)){
            $file_manipulation = new FileManipulation($expr->left->getEndFilePos()+1, $expr->right->getStartFilePos(), ' === ');
            $event->setFileReplacements([$file_manipulation]);
        }

        //solve more cases, for examples, numeric-string vs string
        return true;
    }

}
