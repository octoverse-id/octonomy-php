<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Rector;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Flags each statement that writes to a property the 2.x SDK declares `readonly`, so code written
 * against the 1.x line can be checked before the move (docs/upgrading-1-to-2.md). An opt-in tool for
 * the SDK's users, never part of the SDK's runtime: it loads only when a project's own Rector config
 * names it.
 *
 * It flags rather than fixes, because a write has no mechanical replacement: changing a tag on the
 * server is an update call, and keeping a changed value is the caller's own variable. The flag is a
 * `// TODO octonomy-php 2.x:` comment above the statement, so `rector process --dry-run` lists every
 * such write and exits non-zero while one is unmarked. A statement that already carries the comment
 * is not flagged twice, so once the comments are written, they are the list of what is left.
 *
 * A write is an assignment of any kind, a reference, an increment or decrement, or an `unset()`,
 * aimed at the property itself or reaching through it into an array it holds (an object in that array
 * included), in an expression statement or an `unset()` statement. Reaching through a property that
 * holds an object is not a write to it, since 2.x leaves that object writable. The property counts
 * when the analyser types the object as a class in the
 * `Octoverse\Octonomy\` namespace whose property is readonly: natively on 2.x, or through the
 * `@readonly` docblock the downgrade leaves on 1.x. So the same rule finds the same writes whichever
 * line is installed. A write through an untyped variable, or passed by reference to a function, is
 * not found.
 */
final class FlagModelPropertyWriteRector extends AbstractRector
{
    private const MARKER = 'TODO octonomy-php 2.x:';

    private const SDK_NAMESPACE = 'Octoverse\\Octonomy\\';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Flag writes to properties that are readonly on octonomy-php 2.x', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    $tag = $client->tags()->get($id);
                    $tag->name = 'Renamed';
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    $tag = $client->tags()->get($id);
                    // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
                    $tag->name = 'Renamed';
                    CODE_SAMPLE,
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Expression::class, Unset_::class];
    }

    /**
     * @param Expression|Unset_ $node
     */
    public function refactor(Node $node): ?Node
    {
        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), self::MARKER)) {
                return null;
            }
        }

        $properties = $node instanceof Unset_ ? $this->sdkPropertiesIn($node->vars) : $this->writtenIn($node->expr);
        if ($properties === []) {
            return null;
        }

        $node->setAttribute('comments', [new Comment('// ' . $this->flag($properties)), ...$node->getComments()]);

        return $node;
    }

    /**
     * The readonly SDK properties an expression statement writes to.
     *
     * @return list<string>
     */
    private function writtenIn(Expr $expr): array
    {
        $targets = [];
        // A closure's or an anonymous class's statements are statements of their own, which this rule
        // visits and flags in place; descending here would flag the enclosing statement as well.
        $this->traverseNodesWithCallable($expr, function (Node $node) use (&$targets): ?int {
            if ($node instanceof Closure || $node instanceof Class_) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
                $targets[] = $node->var;
            }
            if ($node instanceof AssignRef) {
                // Taking a reference to a readonly property throws as a write does.
                $targets[] = $node->expr;
            }
            if ($node instanceof PreInc || $node instanceof PreDec || $node instanceof PostInc || $node instanceof PostDec) {
                $targets[] = $node->var;
            }

            return null;
        });

        return $this->sdkPropertiesIn($targets);
    }

    /**
     * The readonly SDK properties among write targets, as `Class::$property`, each once and in
     * source order.
     *
     * @param array<Expr> $targets
     *
     * @return list<string>
     */
    private function sdkPropertiesIn(array $targets): array
    {
        $properties = [];
        foreach ($targets as $target) {
            foreach ($this->destructured($target) as $written) {
                foreach ($this->readonlySdkPropertiesWrittenBy($written) as $property) {
                    if (!in_array($property, $properties, true)) {
                        $properties[] = $property;
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * The readonly SDK properties a write to $target modifies, from the target outwards. The target
     * itself is one. So is each property the write reaches through, as in
     * `$tag->metadata['profile']->label = ...`: PHP fetches that chain for writing, and a readonly
     * property refuses to be fetched so ("Cannot indirectly modify") unless it holds an object, whose
     * own properties and offsets stay writable.
     *
     * @return list<string>
     */
    private function readonlySdkPropertiesWrittenBy(Expr $target): array
    {
        $properties = [];
        $direct = true;
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            if ($target instanceof PropertyFetch) {
                $property = $this->readonlySdkProperty($target, $direct);
                if ($property !== null) {
                    $properties[] = $property;
                }
            }
            $target = $target->var;
            $direct = false;
        }

        return $properties;
    }

    /**
     * The expressions a write target writes to: itself, or each target of a `list()` or `[...] =`,
     * nested ones included.
     *
     * @return list<Expr>
     */
    private function destructured(Expr $target): array
    {
        if (!$target instanceof List_) {
            return [$target];
        }
        $written = [];
        foreach ($target->items as $item) {
            if ($item !== null) {
                array_push($written, ...$this->destructured($item->value));
            }
        }

        return $written;
    }

    /**
     * `Class::$property` when $fetch is a property that is readonly on the SDK class the analyser
     * types its object as, and a write $direct-ly to it, or one reaching through it while it holds
     * something other than an object, throws on 2.x; null for anything else.
     */
    private function readonlySdkProperty(PropertyFetch $fetch, bool $direct): ?string
    {
        $name = $this->getName($fetch->name);
        if ($name === null) {
            return null;
        }

        foreach ($this->getType($fetch->var)->getObjectClassReflections() as $class) {
            if (!str_starts_with($class->getName(), self::SDK_NAMESPACE) || !$class->hasNativeProperty($name)) {
                continue;
            }
            // isReadOnly() is the native keyword (2.x); isReadOnlyByPhpDoc() is the @readonly tag the
            // PHP 7.4 downgrade writes in its place (1.x).
            $property = $class->getNativeProperty($name);
            if (!$property->isReadOnly() && !$property->isReadOnlyByPhpDoc()) {
                continue;
            }
            // The declared type, which 1.x keeps: a write through an object it holds is allowed.
            if (!$direct && $property->getNativeType()->isObject()->yes()) {
                continue;
            }

            return $class->getNativeReflection()->getShortName() . '::$' . $name;
        }

        return null;
    }

    /**
     * @param non-empty-list<string> $properties
     */
    private function flag(array $properties): string
    {
        $what = count($properties) === 1
            ? 'a write to readonly ' . $properties[0] . ' throws'
            : 'writes to readonly ' . implode(', ', $properties) . ' throw';

        return self::MARKER . ' ' . $what . ' there (docs/upgrading-1-to-2.md)';
    }
}
