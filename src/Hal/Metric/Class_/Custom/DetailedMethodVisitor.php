<?php

namespace Hal\Metric\Class_\Custom;

use Hal\Metric\Metrics;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

// Убеждаемся, что глобальные функции phpmetrics доступны
if (!function_exists('getNameOfNode')) {
    require_once __DIR__ . '/../../../../functions.php';
}

class DetailedMethodVisitor extends NodeVisitorAbstract
{
    /** @var Metrics */
    private $metrics;
    private $classMetricStack = [];
    private $currentMethodNode = null;
    private $currentMethodMetric = null;
    private $methodCalls = [];
    private $methodPropertyReads = [];
    private $methodPropertyWrites = [];

    public function __construct(Metrics $metrics)
    {
        $this->metrics = $metrics;
    }

    public function beforeTraverse(array $nodes)
    {
        $this->classMetricStack = [];
        $this->currentMethodNode = null;
        $this->currentMethodMetric = null;
        $this->methodCalls = [];
        $this->methodPropertyReads = [];
        $this->methodPropertyWrites = [];
        return null;
    }

    public function enterNode(Node $node)
    {
        // Track class context
        if (\Hal\Component\Ast\NodeTyper::isOrganizedStructure($node)) {
            $className = \getNameOfNode($node);
            if ($className) {
                array_push($this->classMetricStack, $className);
            }
        }

        // When entering a method, start collecting data
        if (!empty($this->classMetricStack) && $node instanceof Stmt\ClassMethod) {
            $this->currentMethodNode = $node;
            $this->methodCalls = [];
            $this->methodPropertyReads = [];
            $this->methodPropertyWrites = [];
        }

        // Collect method calls, property reads/writes while inside a method
        if ($this->currentMethodNode) {
            if ($node instanceof Node\Expr\MethodCall) {
                $caller = $this->resolveCallerName($node->var);
                $methodName = $this->resolveMethodName($node->name);
                if ($methodName !== null) {
                    $this->methodCalls[] = ['caller' => $caller, 'method' => $methodName];
                }
            }

            if ($node instanceof Node\Expr\StaticCall) {
                $caller = \getNameOfNode($node->class);
                $methodName = $this->resolveMethodName($node->name);
                if ($methodName !== null) {
                    $this->methodCalls[] = ['caller' => $caller, 'method' => $methodName];
                }
            }

            if ($node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                $propName = $this->resolvePropertyName($node->name);
                if ($propName !== null && !in_array($propName, $this->methodPropertyReads)) {
                    $this->methodPropertyReads[] = $propName;
                }
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch) {
                if ($node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this') {
                    $propName = $this->resolvePropertyName($node->var->name);
                    if ($propName !== null && !in_array($propName, $this->methodPropertyWrites)) {
                        $this->methodPropertyWrites[] = $propName;
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // When leaving a method, attach collected data to the metric
        if ($this->currentMethodNode && $node instanceof Stmt\ClassMethod && $node === $this->currentMethodNode) {
            $currentClassName = !empty($this->classMetricStack) ? end($this->classMetricStack) : null;

            if ($currentClassName) {
                $classMetric = $this->metrics->get($currentClassName);

                if ($classMetric) {
                    $methodName = (string) $node->name;
                    $methods = $classMetric->get('methods');

                    if ($methods) {
                        foreach ($methods as $metric) {
                            if ($metric->getName() === $methodName) {
                                $prettyPrinter = new PrettyPrinter\Standard();
                                $methodBody = $prettyPrinter->prettyPrint([$node]);

                                $metric->set('calls', $this->methodCalls);
                                $metric->set('propertyReads', $this->methodPropertyReads);
                                $metric->set('propertyWrites', $this->methodPropertyWrites);
                                $metric->set('body', $methodBody);
                                break;
                            }
                        }
                    }
                }
            }

            $this->currentMethodNode = null;
            $this->methodCalls = [];
            $this->methodPropertyReads = [];
            $this->methodPropertyWrites = [];
        }

        // Pop class context when leaving a class
        if (\Hal\Component\Ast\NodeTyper::isOrganizedStructure($node)) {
            array_pop($this->classMetricStack);
        }

        return null;
    }

    private function resolveCallerName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$' . $node->name;
        }
        return \getNameOfNode($node);
    }

    private function resolveMethodName($nameNode): ?string
    {
        // Если это просто строка (Identifier), возвращаем её
        if (is_string($nameNode)) {
            return $nameNode;
        }

        // Если это Node\Identifier
        if ($nameNode instanceof Node\Identifier) {
            return (string) $nameNode;
        }

        // Если это динамический вызов (переменная), пропускаем
        if ($nameNode instanceof Node\Expr) {
            return null;
        }

        // Пытаемся привести к строке
        return (string) $nameNode;
    }

    private function resolvePropertyName($nameNode): ?string
    {
        // Если это просто строка (Identifier), возвращаем её
        if (is_string($nameNode)) {
            return $nameNode;
        }

        // Если это Node\Identifier или Node\VarLikeIdentifier
        if ($nameNode instanceof Node\Identifier || $nameNode instanceof Node\VarLikeIdentifier) {
            return (string) $nameNode;
        }

        // Если это динамическое свойство (переменная), пропускаем
        if ($nameNode instanceof Node\Expr) {
            return null;
        }

        // Пытаемся привести к строке
        return (string) $nameNode;
    }
}
