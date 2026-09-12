<?php

namespace App\Tests\Unit\Catalog;

use App\Entity\Catalog\Category;
use App\Module\Catalog\CatalogSource;
use App\Module\Catalog\PublicationStatus;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testItBuildsAParentChildHierarchy(): void
    {
        $root = new Category('Fren Sistemi', 'fren-sistemi', CatalogSource::External);
        $child = new Category('Fren Balataları', 'fren-balatalari');

        $child->changeParent($root);

        self::assertSame($root, $child->parent());
        self::assertSame([$child], $root->children());
    }

    public function testItRejectsItselfAsParent(): void
    {
        $category = new Category('Fren Sistemi', 'fren-sistemi');

        $this->expectException(\DomainException::class);

        $category->changeParent($category);
    }

    public function testItRejectsADescendantAsParent(): void
    {
        $root = new Category('Fren Sistemi', 'fren-sistemi');
        $child = new Category('Fren Balataları', 'fren-balatalari');
        $grandchild = new Category('Ön Balatalar', 'on-balatalar');
        $child->changeParent($root);
        $grandchild->changeParent($child);

        $this->expectException(\DomainException::class);

        $root->changeParent($grandchild);
    }

    public function testPublicationTransitionsAreExplicit(): void
    {
        $category = new Category('Fren Sistemi', 'fren-sistemi');

        self::assertSame(PublicationStatus::Draft, $category->publicationStatus());
        $category->publish();
        self::assertSame(PublicationStatus::Published, $category->publicationStatus());
        $category->unpublish();
        self::assertSame(PublicationStatus::Draft, $category->publicationStatus());
    }
}
