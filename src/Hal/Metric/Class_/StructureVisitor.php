<?php

namespace Hal\Metric\Class_;

use Hal\Component\Ast\NodeTyper;
use Hal\Metric\Metrics;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class StructureVisitor extends NodeVisitorAbstract
{
    /** @var Metrics */
    private $metrics;

    public function __construct(Metrics $metrics)
    {
        $this->metrics = $metrics;
    }

    public function leaveNode(Node $node)
    {
        if (!NodeTyper::isOrganizedStructure($node)) {
            return;
        }

        $className = getNameOfNode($node);
        $metric = $this->metrics->get($className);

        if (!$metric) {
            return;
        }

        // 1. type
        if ($node instanceof Stmt\Class_) {
            $metric->set('type', 'class');
        } elseif ($node instanceof Stmt\Interface_) {
            $metric->set('type', 'interface');
        } elseif ($node instanceof Stmt\Trait_) {
            $metric->set('type', 'trait');
        }

        // 2. traits
        $usedTraits = [];
        if (property_exists($node, 'stmts')) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Stmt\TraitUse) {
                    foreach ($stmt->traits as $trait) {
                        $usedTraits[] = $trait->toCodeString();
                    }
                }
            }
        }
        $metric->set('traits', $usedTraits);

        // 3. properties and const
        $properties = [];
        $constants = [];
        if (property_exists($node, 'stmts')) {
            foreach ($node->stmts as $stmt) {

                if ($stmt instanceof Stmt\Property) {
                    $type = 'mixed';
                    if ($stmt->type) {
                        if ($stmt->type instanceof Node\UnionType) {
                            $typeNames = [];
                            foreach ($stmt->type->types as $subType) {
                                $typeNames[] = $subType->toString();
                            }
                            $type = implode('|', $typeNames);
                        } elseif ($stmt->type instanceof Node\IntersectionType) {
                            $typeNames = [];
                            foreach ($stmt->type->types as $subType) {
                                $typeNames[] = $subType->toString();
                            }
                            $type = implode('&', $typeNames);
                        } else {
                            $type = $stmt->type instanceof Node\NullableType
                                ? '?' . $stmt->type->type->toString()
                                : $stmt->type->toString();
                        }
                    }

                    foreach ($stmt->props as $prop) {
                        $properties[] = [
                            'name' => (string) $prop->name,
                            'visibility' => $stmt->isPublic() ? 'public' : ($stmt->isProtected() ? 'protected' : 'private'),
                            'type' => $type,
                            'static' => $stmt->isStatic(),
                        ];
                    }
                }

                if ($stmt instanceof Stmt\ClassConst) {
                    foreach ($stmt->consts as $const) {
                        $constants[] = [
                            'name' => (string) $const->name,
                            'visibility' => $stmt->isPublic() ? 'public' : ($stmt->isProtected() ? 'protected' : 'private'),
                        ];
                    }
                }
            }
        }
        $metric->set('properties', $properties);
        $metric->set('constants', $constants);
    }
}
