<?php declare(strict_types=1);

namespace Shopware\Content\Category\Definition;

use Shopware\Content\Category\Collection\CategoryBasicCollection;
use Shopware\Content\Category\Collection\CategoryDetailCollection;
use Shopware\Content\Category\Event\Category\CategoryDeletedEvent;
use Shopware\Content\Category\Event\Category\CategoryWrittenEvent;
use Shopware\Content\Category\Repository\CategoryRepository;
use Shopware\Content\Category\Struct\CategoryBasicStruct;
use Shopware\Content\Category\Struct\CategoryDetailStruct;
use Shopware\Api\Entity\EntityDefinition;
use Shopware\Api\Entity\EntityExtensionInterface;
use Shopware\Api\Entity\Field\BoolField;
use Shopware\Api\Entity\Field\CatalogField;
use Shopware\Api\Entity\Field\ChildrenAssociationField;
use Shopware\Api\Entity\Field\DateField;
use Shopware\Api\Entity\Field\FkField;
use Shopware\Api\Entity\Field\IdField;
use Shopware\Api\Entity\Field\IntField;
use Shopware\Api\Entity\Field\LongTextField;
use Shopware\Api\Entity\Field\ManyToManyAssociationField;
use Shopware\Api\Entity\Field\ManyToOneAssociationField;
use Shopware\Api\Entity\Field\ParentField;
use Shopware\Api\Entity\Field\ReferenceVersionField;
use Shopware\Api\Entity\Field\StringField;
use Shopware\Api\Entity\Field\TenantIdField;
use Shopware\Api\Entity\Field\TranslatedField;
use Shopware\Api\Entity\Field\TranslationsAssociationField;
use Shopware\Api\Entity\Field\VersionField;
use Shopware\Api\Entity\FieldCollection;
use Shopware\Api\Entity\Write\Flag\CascadeDelete;
use Shopware\Api\Entity\Write\Flag\PrimaryKey;
use Shopware\Api\Entity\Write\Flag\Required;
use Shopware\Api\Entity\Write\Flag\SearchRanking;
use Shopware\Api\Entity\Write\Flag\WriteOnly;
use Shopware\Content\Media\Definition\MediaDefinition;
use Shopware\Content\Product\Definition\ProductCategoryDefinition;
use Shopware\Content\Product\Definition\ProductDefinition;
use Shopware\Content\Product\Definition\ProductSeoCategoryDefinition;
use Shopware\Content\Product\Definition\ProductStreamDefinition;

class CategoryDefinition extends EntityDefinition
{
    /**
     * @var FieldCollection
     */
    protected static $primaryKeys;

    /**
     * @var FieldCollection
     */
    protected static $fields;

    /**
     * @var EntityExtensionInterface[]
     */
    protected static $extensions = [];

    public static function getEntityName(): string
    {
        return 'category';
    }

    public static function getFields(): FieldCollection
    {
        if (self::$fields) {
            return self::$fields;
        }

        self::$fields = new FieldCollection([
            new TenantIdField(),
            (new IdField('id', 'id'))->setFlags(new PrimaryKey(), new Required()),
            new VersionField(),
            new CatalogField(),

            new FkField('parent_id', 'parentId', self::class),
            new ParentField(self::class),
            new ReferenceVersionField(self::class, 'parent_version_id'),

            new FkField('media_id', 'mediaId', MediaDefinition::class),
            new ReferenceVersionField(MediaDefinition::class),

            new FkField('product_stream_id', 'productStreamId', ProductStreamDefinition::class),
            new ReferenceVersionField(ProductStreamDefinition::class),

            new LongTextField('path', 'path'),
            new IntField('position', 'position'),
            new IntField('level', 'level'),
            new StringField('template', 'template'),
            new BoolField('active', 'active'),
            new BoolField('is_blog', 'isBlog'),
            new StringField('external', 'external'),
            new BoolField('hide_filter', 'hideFilter'),
            new BoolField('hide_top', 'hideTop'),
            new StringField('product_box_layout', 'productBoxLayout'),
            new BoolField('hide_sortings', 'hideSortings'),
            new LongTextField('sorting_ids', 'sortingIds'),
            new LongTextField('facet_ids', 'facetIds'),
            new DateField('created_at', 'createdAt'),
            new DateField('updated_at', 'updatedAt'),
            (new TranslatedField(new StringField('name', 'name')))->setFlags(new SearchRanking(self::HIGH_SEARCH_RANKING)),
            new TranslatedField(new LongTextField('path_names', 'pathNames')),
            (new TranslatedField(new LongTextField('meta_keywords', 'metaKeywords')))->setFlags(new SearchRanking(self::LOW_SEARCH_RAKING)),
            new TranslatedField(new StringField('meta_title', 'metaTitle')),
            new TranslatedField(new LongTextField('meta_description', 'metaDescription')),
            new TranslatedField(new StringField('cms_headline', 'cmsHeadline')),
            new TranslatedField(new LongTextField('cms_description', 'cmsDescription')),
            new ManyToOneAssociationField('parent', 'parent_id', self::class, false),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class, false),
            new ManyToOneAssociationField('productStream', 'product_stream_id', ProductStreamDefinition::class, false),
            (new ChildrenAssociationField(self::class))->setFlags(new CascadeDelete()),
            (new TranslationsAssociationField('translations', CategoryTranslationDefinition::class, 'category_id', false, 'id'))->setFlags(new Required(), new CascadeDelete()),
            (new ManyToManyAssociationField('products', ProductDefinition::class, ProductCategoryDefinition::class, false, 'category_id', 'product_id', 'id', 'categories'))->setFlags(new CascadeDelete(), new WriteOnly()),
            (new ManyToManyAssociationField('seoProducts', ProductDefinition::class, ProductSeoCategoryDefinition::class, false, 'category_id', 'product_id'))->setFlags(new CascadeDelete(), new WriteOnly()),
        ]);

        foreach (self::$extensions as $extension) {
            $extension->extendFields(self::$fields);
        }

        return self::$fields;
    }

    public static function getRepositoryClass(): string
    {
        return CategoryRepository::class;
    }

    public static function getBasicCollectionClass(): string
    {
        return CategoryBasicCollection::class;
    }

    public static function getDeletedEventClass(): string
    {
        return CategoryDeletedEvent::class;
    }

    public static function getWrittenEventClass(): string
    {
        return CategoryWrittenEvent::class;
    }

    public static function getBasicStructClass(): string
    {
        return CategoryBasicStruct::class;
    }

    public static function getTranslationDefinitionClass(): ?string
    {
        return CategoryTranslationDefinition::class;
    }

    public static function getDetailStructClass(): string
    {
        return CategoryDetailStruct::class;
    }

    public static function getDetailCollectionClass(): string
    {
        return CategoryDetailCollection::class;
    }
}
