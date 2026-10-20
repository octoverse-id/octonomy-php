<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tools\Rector;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Turns PHPUnit's #[DataProvider('name')] into the `@dataProvider name` annotation PHPUnit 9 reads,
 * and refuses every other PHPUnit attribute.
 *
 * The stock DowngradeAttributeToAnnotationRector cannot do this. Configured for DataProvider it
 * writes a bare `@dataProvider` and drops the method name, and unconfigured it deletes the
 * attribute. Either way the test stops receiving its data. Any other PHPUnit attribute (#[Test],
 * #[Depends], #[Group], ...) would be deleted the same way and change what the suite runs without a
 * word, so this rule throws on one instead, which makes Rector exit non-zero.
 *
 * It hooks the file node, which Rector visits before any class or method, because the downgrade
 * set's stock rule is registered first and would otherwise strip the attributes before this rule
 * saw them.
 */
final class DataProviderAttributeToAnnotationRector extends AbstractRector
{
    private const PHPUNIT_ATTRIBUTES = 'PHPUnit\\Framework\\Attributes\\';

    private const DATA_PROVIDER = 'PHPUnit\\Framework\\Attributes\\DataProvider';

    public function __construct(
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly DocBlockUpdater $docBlockUpdater,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn #[DataProvider] into @dataProvider and refuse other PHPUnit attributes', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    #[DataProvider('cases')]
                    public function testIt(int $value): void {}
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    /**
                     * @dataProvider cases
                     */
                    public function testIt(int $value): void {}
                    CODE_SAMPLE,
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FileNode::class];
    }

    /**
     * @param FileNode $node
     */
    public function refactor(Node $node): ?Node
    {
        $changed = false;
        foreach ($this->betterNodeFinder->findInstanceOf($node->stmts, ClassLike::class) as $classLike) {
            $this->refuseAll($classLike->attrGroups, $classLike);
            foreach ($classLike->getMethods() as $method) {
                $changed = $this->convert($method) || $changed;
            }
        }

        return $changed ? $node : null;
    }

    private function convert(ClassMethod $method): bool
    {
        $changed = false;
        foreach ($method->attrGroups as $groupKey => $group) {
            foreach ($group->attrs as $key => $attribute) {
                if ($this->getName($attribute->name) !== self::DATA_PROVIDER) {
                    $this->refuse($attribute, $method);
                    continue;
                }
                $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($method);
                $phpDocInfo->addPhpDocTagNode(new PhpDocTagNode('@dataProvider', new GenericTagValueNode($this->providerOf($attribute, $method))));
                $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($method);
                unset($group->attrs[$key]);
                $changed = true;
            }
            if ($group->attrs === []) {
                unset($method->attrGroups[$groupKey]);
            }
        }

        return $changed;
    }

    private function providerOf(Attribute $attribute, ClassMethod $method): string
    {
        $value = count($attribute->args) === 1 ? $attribute->args[0]->value : null;
        if (!$value instanceof String_ || $value->value === '') {
            throw new \LogicException(sprintf(
                '%s: #[DataProvider] must name its method as one string literal to downgrade',
                $this->where($method),
            ));
        }

        return $value->value;
    }

    /**
     * @param AttributeGroup[] $groups
     */
    private function refuseAll(array $groups, Node $owner): void
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $this->refuse($attribute, $owner);
            }
        }
    }

    private function refuse(Attribute $attribute, Node $owner): void
    {
        $name = (string) $this->getName($attribute->name);
        if (str_starts_with($name, self::PHPUNIT_ATTRIBUTES)) {
            throw new \LogicException(sprintf(
                '%s: #[%s] has no PHPUnit 9 equivalent here, and the downgrade would delete it; teach %s to convert it',
                $this->where($owner),
                $name,
                self::class,
            ));
        }
    }

    private function where(Node $owner): string
    {
        $name = $owner instanceof ClassMethod || $owner instanceof ClassLike ? (string) $this->getName($owner) : '?';

        return sprintf('%s:%d %s', $this->getFile()->getFilePath(), $owner->getStartLine(), $name);
    }
}
