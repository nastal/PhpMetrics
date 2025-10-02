<?php

namespace Hal\Metric\Class_\Custom;

use Hal\Component\Ast\NodeTyper;
use Hal\Metric\Metrics;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

class DetailedMethodVisitor extends NodeVisitorAbstract
{
    /** @var Metrics */
    private $metrics;

    /** @var \Hal\Metric\ClassMetric|null */
    private $currentClassMetric;

    /** @var \Hal\Metric\FunctionMetric|null */
    private $currentMethodMetric;

    public function __construct(Metrics $metrics)
    {
        $this->metrics = $metrics;
    }

    public function beforeTraverse(array $nodes)
    {
        $this->currentClassMetric = null;
        $this->currentMethodMetric = null;
        return null;
    }

    public function enterNode(Node $node)
    {

        if (NodeTyper::isOrganizedStructure($node)) {
            $className = getNameOfNode($node);
            $this->currentClassMetric = $this->metrics->get($className);
        }


        if ($this->currentClassMetric && $node instanceof Stmt\ClassMethod) {
            $methodName = (string) $node->name;

            foreach ($this->currentClassMetric->get('methods') as $metric) {
                if ($metric->getName() === $methodName) {
                    $this->currentMethodMetric = $metric;

                    $this->currentMethodMetric->set('calls', []);
                    $this->currentMethodMetric->set('propertyReads', []);
                    $this->currentMethodMetric->set('propertyWrites', []);
                    break;
                }
            }
        }


        if ($this->currentMethodMetric) {
            // 1. Собираем вызовы методов: $this->method() или $var->method()
            if ($node instanceof Node\Expr\MethodCall) {
                $caller = '$this';
                if ($node->var instanceof Node\Expr\Variable) {
                    $caller = '$' . $node->var->name;
                }
                $calls = $this->currentMethodMetric->get('calls');
                $calls[] = ['caller' => $caller, 'method' => (string) $node->name];
                $this->currentMethodMetric->set('calls', $calls);
            }

            // 2. Собираем статические вызовы: self::method() или Class::method()
            if ($node instanceof Node\Expr\StaticCall) {
                $caller = getNameOfNode($node->class);
                $calls = $this->currentMethodMetric->get('calls');
                $calls[] = ['caller' => $caller, 'method' => (string) $node->name];
                $this->currentMethodMetric->set('calls', $calls);
            }

            // 3. Собираем чтение свойств: $this->property
            if ($node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                $reads = $this->currentMethodMetric->get('propertyReads');
                $reads[] = (string) $node->name;
                $this->currentMethodMetric->set('propertyReads', array_unique($reads));
            }

            // 4. Собираем запись в свойства: $this->property = ...
            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch) {
                if ($node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this') {
                    $writes = $this->currentMethodMetric->get('propertyWrites');
                    $writes[] = (string) $node->var->name;
                    $this->currentMethodMetric->set('propertyWrites', array_unique($writes));
                }
            }
        }
    }

    public function leaveNode(Node $node)
    {
        // Покидаем узел метода
        if ($this->currentMethodMetric && $node instanceof Stmt\ClassMethod) {
            // Убеждаемся, что мы выходим из того же метода, в котором были
            if ($this->currentMethodMetric->getName() === (string) $node->name) {
                // Получаем тело метода в виде строки
                $prettyPrinter = new PrettyPrinter\Standard();
                $methodBody = $prettyPrinter->prettyPrint([$node]);
                $this->currentMethodMetric->set('body', $methodBody);
                // Сбрасываем контекст метода
                $this->currentMethodMetric = null;
            }
        }

        // Покидаем узел класса, сбрасываем контекст класса
        if ($this->currentClassMetric && NodeTyper::isOrganizedStructure($node)) {
            $this->currentClassMetric = null;
        }

        return null; // Ничего не изменяем в структуре дерева
    }
}
