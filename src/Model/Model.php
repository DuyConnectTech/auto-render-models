<?php

namespace Connecttech\AutoRenderModels\Model;

use Connecttech\AutoRenderModels\Model\Relation;
use Connecttech\AutoRenderModels\Model\Relations\BelongsTo;
use Connecttech\AutoRenderModels\Model\Relations\ReferenceFactory;
use Connecttech\AutoRenderModels\Meta\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Fluent;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Model
 *
 * Đại diện metadata của một Eloquent Model được sinh ra từ schema:
 * - Nắm thông tin bảng (blueprint)
 * - Ánh xạ cột -> properties, casts, fillable, hidden, hints
 * - Xây dựng quan hệ (belongsTo, hasMany, v.v.) từ foreign keys và references
 * - Đọc config để quyết định namespace, parent class, timestamps, soft deletes, vv.
 *
 * Lưu ý: đây KHÔNG phải Eloquent model thực tế, mà là lớp trung gian để generator dùng.
 */
class Model
{
    /**
     * Blueprint mô tả schema/table hiện tại.
     *
     * @var \Connecttech\AutoRenderModels\Meta\Blueprint
     */
    private $blueprint;

    /**
     * Factory dùng để tạo các Model khác (liên quan / reference).
     *
     * @var \Connecttech\AutoRenderModels\Model\Factory
     */
    private $factory;

    /**
     * Danh sách thuộc tính (tên cột => PHP type hint).
     *
     * @var array<string, string>
     */
    protected $properties = [];

    /**
     * Danh sách quan hệ (Relation instances).
     *
     * @var \Connecttech\AutoRenderModels\Model\Relation[]
     */
    protected $relations = [];

    /**
     * Danh sách blueprint reference (bảng khác có liên quan).
     *
     * @var \Connecttech\AutoRenderModels\Meta\Blueprint[]
     */
    protected $references = [];

    /**
     * Danh sách thuộc tính bị ẩn (ẩn khỏi array/json).
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Danh sách thuộc tính có thể mass-assign.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    /**
     * Danh sách casts (property => cast type).
     *
     * @var array<string, string>
     */
    protected $casts = [];

    /**
     * Mutators được truyền từ factory để can thiệp vào từng cột.
     *
     * @var \Connecttech\AutoRenderModels\Model\Mutator[]
     */
    protected $mutators = [];

    /**
     * Các mutation đã được build (method name + body).
     *
     * @var \Connecttech\AutoRenderModels\Model\Mutation[]
     */
    protected $mutations = [];

    /**
     * Hints (comment từ column comment, dùng cho PHPDoc @property).
     *
     * @var array<string, string>
     */
    protected $hints = [];

    /**
     * Namespace gốc của model.
     *
     * @var string
     */
    protected $namespace;

    /**
     * Tên parent class đầy đủ (FQN).
     *
     * @var string
     */
    protected $parentClass;

    /**
     * Flag bật/tắt timestamps.
     *
     * @var bool
     */
    protected $timestamps = true;

    /**
     * Tên field created_at (có thể custom).
     *
     * @var string
     */
    protected $CREATED_AT;

    /**
     * Tên field updated_at (có thể custom).
     *
     * @var string
     */
    protected $UPDATED_AT;

    /**
     * Flag bật/tắt soft deletes.
     *
     * @var bool
     */
    protected $softDeletes = false;

    /**
     * Tên field deleted_at (có thể custom).
     *
     * @var string
     */
    protected $DELETED_AT;

    /**
     * Có xuất trường connection trong model hay không.
     *
     * @var bool
     */
    protected $showConnection = false;

    /**
     * Tên connection.
     *
     * @var string
     */
    protected $connection;

    /**
     * Thông tin primary key (từ blueprint).
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $primaryKeys;

    /**
     * Column object tương ứng với primary key.
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $primaryKeyColumn;

    /**
     * Giá trị perPage default cho model.
     *
     * @var int
     */
    protected $perPage;

    /**
     * Format date cho model.
     *
     * @var string
     */
    protected $dateFormat;

    /**
     * Có load relations hay không (tối ưu khi chỉ cần properties).
     *
     * @var bool
     */
    protected $loadRelations;

    /**
     * Flag cho cross-database relationships (chưa dùng nhiều).
     *
     * @var bool
     */
    protected $hasCrossDatabaseRelationships = false;

    /**
     * Prefix của table (nếu có).
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * Chiến lược đặt tên relation (vd: related, full, ...).
     *
     * @var string
     */
    protected $relationNameStrategy = '';

    /**
     * Có sinh return type cho các method quan hệ hay không.
     *
     * @var bool
     */
    protected $definesReturnTypes = false;

    /**
     * ModelClass constructor.
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint       $blueprint    Thông tin schema/table.
     * @param \Connecttech\AutoRenderModels\Model\Factory        $factory      Factory để tạo model liên quan.
     * @param \Connecttech\AutoRenderModels\Model\Mutator[]      $mutators     Danh sách mutator áp dụng trên cột.
     * @param bool                                               $loadRelations Có build relations hay không.
     */
    public function __construct(Blueprint $blueprint, Factory $factory, $mutators = [], $loadRelations = true)
    {
        $this->blueprint = $blueprint;
        $this->factory = $factory;
        $this->loadRelations = $loadRelations;
        $this->mutators = $mutators;

        // Đọc config & set các option (namespace, parent, timestamps, soft deletes, vv.)
        $this->configure();

        // Phân tích blueprint -> build properties, casts, fillable, hidden, relations, ...
        $this->fill();
    }

    /**
     * Đọc config và cấu hình các thuộc tính chung của model.
     *
     * @return $this
     */
    protected function configure()
    {
        $this->withNamespace($this->config('namespace'));
        $this->withParentClass($this->config('parent'));

        // Timestamps settings
        $this->withTimestamps($this->config('timestamps.enabled', $this->config('timestamps', true)));
        $this->withCreatedAtField($this->config('timestamps.fields.CREATED_AT', $this->getDefaultCreatedAtField()));
        $this->withUpdatedAtField($this->config('timestamps.fields.UPDATED_AT', $this->getDefaultUpdatedAtField()));

        // Soft deletes settings
        $this->withSoftDeletes($this->config('soft_deletes.enabled', $this->config('soft_deletes', false)));
        $this->withDeletedAtField($this->config('soft_deletes.field', $this->getDefaultDeletedAtField()));

        // Connection settings
        $this->withConnection($this->config('connection', false));
        $this->withConnectionName($this->blueprint->connection());

        // Pagination settings
        $this->withPerPage($this->config('per_page', $this->getDefaultPerPage()));

        // Dates settings
        $this->withDateFormat($this->config('date_format', $this->getDefaultDateFormat()));

        // Table Prefix settings
        $this->withTablePrefix($this->config('table_prefix', $this->getDefaultTablePrefix()));

        // Relation name settings
        $this->withRelationNameStrategy($this->config('relation_name_strategy', $this->getDefaultRelationNameStrategy()));

        // Bật/tắt generate return types cho methods relations
        $this->definesReturnTypes = $this->config('enable_return_types', false);

        return $this;
    }

    /**
     * Phân tích thông tin từ Blueprint:
     * - Lấy primary key
     * - Duyệt từng column => parseColumn()
     * - Duyệt relations (foreign keys) => BelongsTo
     * - Duyệt references (bảng khác trỏ về) => ReferenceFactory
     *
     * @return void
     */
    protected function fill()
    {
        $this->primaryKeys = $this->blueprint->primaryKey();

        // Process columns
        foreach ($this->blueprint->columns() as $column) {
            $this->parseColumn($column);
        }

        if (! $this->loadRelations) {
            return;
        }

        // Quan hệ belongsTo từ foreign keys
        foreach ($this->blueprint->relations() as $relation) {
            $model = $this->makeRelationModel($relation);
            $belongsTo = new BelongsTo($relation, $this, $model);
            $this->relations[$belongsTo->name()] = $belongsTo;
        }

        // Quan hệ ngược (bảng khác reference tới bảng hiện tại)
        foreach ($this->factory->referencing($this) as $related) {
            $factory = new ReferenceFactory($related, $this);
            $references = $factory->make();
            foreach ($references as $reference) {
                $this->relations[$reference->name()] = $reference;
            }
        }
    }

    /**
     * Phân tích một column:
     * - Xác định cast type (từ DB type -> PHP cast, có thể override từ config)
     * - Xác định hidden/fillable
     * - Gọi mutator nếu có
     * - Lưu comment -> hint
     * - Lưu PHP type hint cho @property
     * - Track primaryKey column
     *
     * @param \Illuminate\Support\Fluent $column
     *
     * @return void
     */
    protected function parseColumn(Fluent $column)
    {
        // TODO: Check type cast is OK
        $cast = $column->type;

        $propertyName = $this->usesPropertyConstants() ? 'self::' . strtoupper($column->name) : $column->name;

        // Do vấn đề cast null -> Carbon, field soft delete sẽ được cast về string
        if ($column->name == $this->getDeletedAtField()) {
            $cast = 'string';
        }

        // Track casts, bỏ qua timestamps (CREATED_AT, UPDATED_AT)
        if ($cast != 'string' && !in_array($propertyName, [$this->CREATED_AT, $this->UPDATED_AT])) {
            $this->casts[$propertyName] = $cast;
        }

        // Override cast từ config (theo pattern)
        foreach ($this->config('casts', []) as $pattern => $casting) {
            if (Str::is($pattern, $column->name)) {
                $this->casts[$propertyName] = $cast = $casting;
                break;
            }
        }

        // Hidden?
        if ($this->isHidden($column->name)) {
            $this->hidden[] = $propertyName;
        }

        // Fillable?
        if ($this->isFillable($column->name)) {
            $this->fillable[] = $propertyName;
        }

        // Áp dụng mutator cho cột nếu có
        $this->mutate($column->name);

        // Comment -> hint dùng cho PHPDoc
        if (! empty($column->comment)) {
            $this->hints[$column->name] = $column->comment;
        }

        // PHP type hint cho @property
        $hint = $this->phpTypeHint($cast, $column->nullable);
        $this->properties[$column->name] = $hint;

        // Track primary key column
        if ($column->name == $this->getPrimaryKey()) {
            $this->primaryKeyColumn = $column;
        }
    }

    /**
     * Áp dụng tất cả Mutator lên một column cụ thể.
     *
     * @param string $column
     *
     * @return void
     */
    protected function mutate($column)
    {
        foreach ($this->mutators as $mutator) {
            if ($mutator->applies($column, $this->getBlueprint())) {
                $this->mutations[] = new Mutation(
                    $mutator->getName($column, $this),
                    $mutator->getBody($column, $this)
                );
            }
        }
    }

    /**
     * Tạo Model tương ứng cho bảng nằm ở đầu bên kia của relation.
     *
     * Nếu relation trỏ về chính bảng hiện tại -> trả về $this
     * Nếu là bảng khác -> nhờ Factory tạo Model mới (không load relations).
     *
     * @param \Illuminate\Support\Fluent $relation
     *
     * @return $this|\Connecttech\AutoRenderModels\Model\Model
     */
    public function makeRelationModel(Fluent $relation)
    {
        list($database, $table) = array_values($relation->on);

        if ($this->blueprint->is($database, $table)) {
            return $this;
        }

        return $this->factory->makeModel($database, $table, false);
    }

    /**
     * Map cast type của Eloquent sang PHP type hint để dùng trong @property.
     *
     * @param string $castType
     * @param bool   $nullable
     *
     * @todo Make tests
     *
     * @return string
     */
    public function phpTypeHint($castType, $nullable)
    {
        $type = $castType;

        switch ($castType) {
            case 'object':
                $type = '\stdClass';
                break;
            case 'array':
            case 'json':
                $type = 'array';
                break;
            case 'collection':
                $type = '\Illuminate\Support\Collection';
                break;
            case 'datetime':
                $type = '\Carbon\Carbon';
                break;
            case 'binary':
                $type = 'string';
                break;
        }

        if ($nullable) {
            return $type . '|null';
        }

        return $type;
    }

    /**
     * Lấy tên schema hiện tại.
     *
     * @return string
     */
    public function getSchema()
    {
        return $this->blueprint->schema();
    }

    /**
     * Lấy tên bảng (có thể remove prefix nếu $andRemovePrefix = true).
     *
     * @param bool $andRemovePrefix
     *
     * @return string
     */
    public function getTable($andRemovePrefix = false)
    {
        if ($andRemovePrefix) {
            return $this->removeTablePrefix($this->blueprint->table());
        }

        return $this->blueprint->table();
    }

    /**
     * Lấy "schema.table".
     *
     * @return string
     */
    public function getQualifiedTable()
    {
        return $this->blueprint->qualifiedTable();
    }

    /**
     * Tên bảng dùng trong query (có thể là qualified hoặc chỉ table).
     *
     * @return string
     */
    public function getTableForQuery()
    {
        return $this->shouldQualifyTableName()
            ? $this->getQualifiedTable()
            : $this->getTable();
    }

    /**
     * Có dùng qualified table name (schema.table) hay không.
     *
     * @return bool
     */
    public function shouldQualifyTableName()
    {
        return $this->config('qualified_tables', false);
    }

    /**
     * Có pluralize table name khi generate class name hay không.
     *
     * @return bool
     */
    public function shouldPluralizeTableName()
    {
        $pluralize = (bool) $this->config('pluralize', true);

        $overridePluralizeFor = $this->config('override_pluralize_for', []);
        if (count($overridePluralizeFor) > 0) {
            foreach ($overridePluralizeFor as $except) {
                if ($except == $this->getTable()) {
                    return ! $pluralize;
                }
            }
        }

        return $pluralize;
    }

    /**
     * Có convert table name về lowercase trước khi Studly hay không.
     *
     * @return bool
     */
    public function shouldLowerCaseTableName()
    {
        return (bool) $this->config('lower_table_name_first', false);
    }

    /**
     * Set danh sách references ngoài (nếu được inject từ ngoài).
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint[] $references
     *
     * @return void
     */
    public function withReferences($references)
    {
        $this->references = $references;
    }

    /**
     * Set namespace cho model.
     *
     * @param string $namespace
     *
     * @return $this
     */
    public function withNamespace($namespace)
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * Lấy namespace của model.
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * Lấy chiến lược đặt tên relation.
     *
     * @return string
     */
    public function getRelationNameStrategy()
    {
        return $this->relationNameStrategy;
    }

    /**
     * Namespace base dùng cho BaseModel (nếu dùng base files).
     *
     * @return string
     */
    public function getBaseNamespace()
    {
        return $this->usesBaseFiles()
            ? $this->getNamespace() . '\\Base'
            : $this->getNamespace();
    }

    /**
     * Set parent class (FQN).
     *
     * @param string $parent
     *
     * @return $this
     */
    public function withParentClass($parent)
    {
        $this->parentClass = '\\' . ltrim($parent, '\\');

        return $this;
    }

    /**
     * Lấy parent class (FQN) hiện tại.
     *
     * @return string
     */
    public function getParentClass()
    {
        return $this->parentClass;
    }

    /**
     * Lấy FQN của UserModel (non-base).
     *
     * @return string
     */
    public function getQualifiedUserClassName()
    {
        return '\\' . $this->getNamespace() . '\\' . $this->getClassName();
    }

    /**
     * Lấy tên class của model.
     * - Có thể override trong config: model_names.{table}
     * - Nếu không, generate từ table name (có/không lower, có/không pluralize).
     *
     * @return string
     */
    public function getClassName()
    {
        // Model names can be manually overridden by users in the config file.
        // If a config entry exists for this table, use that name, rather than generating one.
        $overriddenName = $this->config('model_names.' . $this->getTable());
        if ($overriddenName) {
            return $overriddenName;
        }

        if ($this->shouldLowerCaseTableName()) {
            return Str::studly(Str::lower($this->getRecordName()));
        }

        return Str::studly($this->getRecordName());
    }

    /**
     * Lấy "record name" từ table name:
     * - Nếu pluralize: singular(removePrefix(table))
     * - Nếu không: chỉ removePrefix(table)
     *
     * @return string
     */
    public function getRecordName()
    {
        if ($this->shouldPluralizeTableName()) {
            return Str::singular($this->removeTablePrefix($this->blueprint->table()));
        }

        return $this->removeTablePrefix($this->blueprint->table());
    }

    /**
     * Bật/tắt timestamps.
     *
     * @param bool $timestampsEnabled
     *
     * @return $this
     */
    public function withTimestamps($timestampsEnabled)
    {
        $this->timestamps = $timestampsEnabled;

        return $this;
    }

    /**
     * Model có dùng timestamps được hay không:
     * - Flag timestamps bật
     * - Bảng có đủ cả created_at & updated_at field.
     *
     * @return bool
     */
    public function usesTimestamps()
    {
        return $this->timestamps &&
            $this->blueprint->hasColumn($this->getCreatedAtField()) &&
            $this->blueprint->hasColumn($this->getUpdatedAtField());
    }

    /**
     * Set tên field created_at.
     *
     * @param string $field
     *
     * @return $this
     */
    public function withCreatedAtField($field)
    {
        $this->CREATED_AT = $field;

        return $this;
    }

    /**
     * Lấy tên field created_at.
     *
     * @return string
     */
    public function getCreatedAtField()
    {
        return $this->CREATED_AT;
    }

    /**
     * Kiểm tra có đang dùng custom created_at field hay không.
     *
     * @return bool
     */
    public function hasCustomCreatedAtField()
    {
        return $this->usesTimestamps() &&
            $this->getCreatedAtField() != $this->getDefaultCreatedAtField();
    }

    /**
     * Tên default created_at của Eloquent.
     *
     * @return string
     */
    public function getDefaultCreatedAtField()
    {
        return Eloquent::CREATED_AT;
    }

    /**
     * Set tên field updated_at.
     *
     * @param string $field
     *
     * @return $this
     */
    public function withUpdatedAtField($field)
    {
        $this->UPDATED_AT = $field;

        return $this;
    }

    /**
     * Lấy tên field updated_at.
     *
     * @return string
     */
    public function getUpdatedAtField()
    {
        return $this->UPDATED_AT;
    }

    /**
     * Có dùng custom updated_at field không.
     *
     * @return bool
     */
    public function hasCustomUpdatedAtField()
    {
        return $this->usesTimestamps() &&
            $this->getUpdatedAtField() != $this->getDefaultUpdatedAtField();
    }

    /**
     * Tên default updated_at của Eloquent.
     *
     * @return string
     */
    public function getDefaultUpdatedAtField()
    {
        return Eloquent::UPDATED_AT;
    }

    /**
     * Bật/tắt soft deletes.
     *
     * @param bool $softDeletesEnabled
     *
     * @return $this
     */
    public function withSoftDeletes($softDeletesEnabled)
    {
        $this->softDeletes = $softDeletesEnabled;

        return $this;
    }

    /**
     * Model có dùng soft deletes không:
     * - Flag bật
     * - Bảng có column deleted_at tương ứng.
     *
     * @return bool
     */
    public function usesSoftDeletes()
    {
        return $this->softDeletes &&
            $this->blueprint->hasColumn($this->getDeletedAtField());
    }

    /**
     * Set tên field deleted_at.
     *
     * @param string $field
     *
     * @return $this
     */
    public function withDeletedAtField($field)
    {
        $this->DELETED_AT = $field;

        return $this;
    }

    /**
     * Lấy tên field deleted_at.
     *
     * @return string
     */
    public function getDeletedAtField()
    {
        return $this->DELETED_AT;
    }

    /**
     * Có dùng custom deleted_at field không.
     *
     * @return bool
     */
    public function hasCustomDeletedAtField()
    {
        return $this->usesSoftDeletes() &&
            $this->getDeletedAtField() != $this->getDefaultDeletedAtField();
    }

    /**
     * Tên default deleted_at.
     *
     * @return string
     */
    public function getDefaultDeletedAtField()
    {
        return 'deleted_at';
    }

    /**
     * Lấy danh sách traits cần use trong model.
     * - Config 'use' phải là array
     * - Tự động thêm SoftDeletes::class nếu usesSoftDeletes()
     *
     * @return array
     */
    public function getTraits()
    {
        $traits = $this->config('use', []);

        if (! is_array($traits)) {
            throw new \RuntimeException('Config use must be an array of valid traits to append to each model.');
        }

        if ($this->usesSoftDeletes()) {
            $traits = array_merge([SoftDeletes::class], $traits);
        }

        return $traits;
    }

    /**
     * Có cần khai báo $table trong model hay không.
     *
     * @return bool
     */
    public function needsTableName()
    {
        return false === $this->shouldQualifyTableName() ||
            $this->shouldRemoveTablePrefix() ||
            $this->blueprint->table() != Str::plural($this->getRecordName()) ||
            ! $this->shouldPluralizeTableName();
    }

    /**
     * Có remove table prefix hay không.
     *
     * @return string
     */
    public function shouldRemoveTablePrefix()
    {
        return ! empty($this->tablePrefix);
    }

    /**
     * Set table prefix.
     *
     * @param string $tablePrefix
     *
     * @return void
     */
    public function withTablePrefix($tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Set chiến lược đặt tên relation.
     *
     * @param string $relationNameStrategy
     *
     * @return void
     */
    public function withRelationNameStrategy($relationNameStrategy)
    {
        $this->relationNameStrategy = $relationNameStrategy;
    }

    /**
     * Xoá prefix khỏi tên bảng (nếu có).
     *
     * @param string $table
     *
     * @return string
     */
    public function removeTablePrefix($table)
    {
        if (($this->shouldRemoveTablePrefix()) && (substr($table, 0, strlen($this->tablePrefix)) == $this->tablePrefix)) {
            $table = substr($table, strlen($this->tablePrefix));
        }

        return $table;
    }

    /**
     * Bật/tắt hiển thị $connection trên model.
     *
     * @param bool $showConnection
     *
     * @return void
     */
    public function withConnection($showConnection)
    {
        $this->showConnection = $showConnection;
    }

    /**
     * Set tên connection cho model.
     *
     * @param string $connection
     *
     * @return void
     */
    public function withConnectionName($connection)
    {
        $this->connection = $connection;
    }

    /**
     * Có cần show connection trong model không.
     *
     * @return bool
     */
    public function shouldShowConnection()
    {
        return (bool) $this->showConnection;
    }

    /**
     * Lấy tên connection.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return $this->connection;
    }

    /**
     * Có dùng custom primary key không (1 cột và khác 'id').
     *
     * @return bool
     */
    public function hasCustomPrimaryKey()
    {
        return count($this->primaryKeys->columns) == 1 &&
            $this->getPrimaryKey() != $this->getDefaultPrimaryKeyField();
    }

    /**
     * Tên default primary key.
     *
     * @return string
     */
    public function getDefaultPrimaryKeyField()
    {
        return 'id';
    }

    /**
     * Lấy tên primary key (chỉ hỗ trợ single-column).
     *
     * @todo: Improve it
     *
     * @return string|null
     */
    public function getPrimaryKey()
    {
        if (empty($this->primaryKeys->columns)) {
            return null;
        }

        return $this->primaryKeys->columns[0];
    }

    /**
     * Lấy kiểu dữ liệu của primary key.
     *
     * @todo: check
     *
     * @return string
     */
    public function getPrimaryKeyType()
    {
        return $this->primaryKeyColumn->type;
    }

    /**
     * Có dùng custom cast cho primary key không.
     *
     * @todo: Check whether it is necessary
     *
     * @return bool
     */
    public function hasCustomPrimaryKeyCast()
    {
        return $this->getPrimaryKeyType() != $this->getDefaultPrimaryKeyType();
    }

    /**
     * Kiểu mặc định của primary key.
     *
     * @return string
     */
    public function getDefaultPrimaryKeyType()
    {
        return 'int';
    }

    /**
     * Primary key không auto-increment?
     *
     * @return bool
     */
    public function doesNotAutoincrement()
    {
        return ! $this->autoincrement();
    }

    /**
     * Primary key có auto-increment không.
     *
     * @return bool
     */
    public function autoincrement()
    {
        if ($this->primaryKeyColumn) {
            return $this->primaryKeyColumn->autoincrement === true;
        }

        return false;
    }

    /**
     * Set perPage cho model.
     *
     * @param int $perPage
     *
     * @return void
     */
    public function withPerPage($perPage)
    {
        $this->perPage = (int) $perPage;
    }

    /**
     * Lấy perPage hiện tại.
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * Có dùng custom perPage không.
     *
     * @return bool
     */
    public function hasCustomPerPage()
    {
        return $this->perPage != $this->getDefaultPerPage();
    }

    /**
     * Giá trị perPage default.
     *
     * @return int
     */
    public function getDefaultPerPage()
    {
        return 15;
    }

    /**
     * Set date format cho model.
     *
     * @param string $format
     *
     * @return $this
     */
    public function withDateFormat($format)
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Lấy date format hiện tại.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->dateFormat;
    }

    /**
     * Có dùng custom date format không.
     *
     * @return bool
     */
    public function hasCustomDateFormat()
    {
        return $this->dateFormat != $this->getDefaultDateFormat();
    }

    /**
     * Date format default.
     *
     * @return string
     */
    public function getDefaultDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Prefix table mặc định.
     *
     * @return string
     */
    public function getDefaultTablePrefix()
    {
        return '';
    }

    /**
     * Chiến lược đặt tên relation mặc định.
     *
     * @return string
     */
    public function getDefaultRelationNameStrategy()
    {
        return 'related';
    }

    /**
     * Model có khai báo bất kỳ casts nào không.
     *
     * @return bool
     */
    public function hasCasts()
    {
        return ! empty($this->getCasts());
    }

    /**
     * Lấy danh sách casts, bỏ qua primary key nếu auto-increment.
     *
     * @return array<string, string>
     */
    public function getCasts()
    {
        if (
            array_key_exists($this->getPrimaryKey(), $this->casts) &&
            $this->autoincrement()
        ) {
            unset($this->casts[$this->getPrimaryKey()]);
        }

        return $this->casts;
    }

    /**
     * Có bất kỳ field nào là datetime không.
     *
     * @return bool
     */
    public function hasDates()
    {
        return ! empty($this->getDates());
    }

    /**
     * Lấy danh sách các field được cast thành datetime, trừ timestamps.
     *
     * @return array
     */
    public function getDates()
    {
        return array_diff(
            array_filter($this->casts, function (string $cast) {
                return $cast === 'datetime';
            }),
            [$this->CREATED_AT, $this->UPDATED_AT]
        );
    }

    /**
     * Có dùng snake case cho attributes không.
     *
     * @return bool
     */
    public function usesSnakeAttributes()
    {
        return (bool) $this->config('snake_attributes', true);
    }

    /**
     * Ngược lại với usesSnakeAttributes().
     *
     * @return bool
     */
    public function doesNotUseSnakeAttributes()
    {
        return ! $this->usesSnakeAttributes();
    }

    /**
     * Có lưu hint cho properties không.
     *
     * @return bool
     */
    public function hasHints()
    {
        return ! empty($this->getHints());
    }

    /**
     * Lấy danh sách hints (column => comment).
     *
     * @return array<string, string>
     */
    public function getHints()
    {
        return $this->hints;
    }

    /**
     * Lấy map properties (column => PHP type hint).
     *
     * @return array<string, string>
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * Kiểm tra một property có tồn tại không.
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasProperty($name)
    {
        return array_key_exists($name, $this->getProperties());
    }

    /**
     * Lấy danh sách relations.
     *
     * @return \Connecttech\AutoRenderModels\Model\Relation[]
     */
    public function getRelations()
    {
        return $this->relations;
    }

    /**
     * Model có bất kỳ quan hệ nào không.
     *
     * @return bool
     */
    public function hasRelations()
    {
        return ! empty($this->relations);
    }

    /**
     * Lấy danh sách mutation đã được build.
     *
     * @return \Connecttech\AutoRenderModels\Model\Mutation[]
     */
    public function getMutations()
    {
        return $this->mutations;
    }

    /**
     * Kiểm tra một cột có nằm trong danh sách hidden (theo pattern) không.
     *
     * @param string $column
     *
     * @return bool
     */
    public function isHidden($column)
    {
        $attributes = $this->config('hidden', []);

        if (! is_array($attributes)) {
            throw new \RuntimeException('Config field [hidden] must be an array of attributes to hide from array or json.');
        }

        foreach ($attributes as $pattern) {
            if (Str::is($pattern, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Model có bất kỳ hidden attribute nào không.
     *
     * @return bool
     */
    public function hasHidden()
    {
        return ! empty($this->hidden);
    }

    /**
     * Lấy danh sách hidden attributes.
     *
     * @return array<int, string>
     */
    public function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Kiểm tra một cột có fillable không (không match với guarded, primary key, timestamps, deleted_at).
     *
     * @param string $column
     *
     * @return bool
     */
    public function isFillable($column)
    {
        $guarded = $this->config('guarded', []);

        if (! is_array($guarded)) {
            throw new \RuntimeException('Config field [guarded] must be an array of attributes to protect from mass assignment.');
        }

        $protected = [
            $this->getCreatedAtField(),
            $this->getUpdatedAtField(),
            $this->getDeletedAtField(),
        ];

        if ($this->primaryKeys->columns) {
            $protected = array_merge($protected, $this->primaryKeys->columns);
        }

        foreach (array_merge($guarded, $protected) as $pattern) {
            if (Str::is($pattern, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Model có bất kỳ fillable attribute nào không.
     *
     * @return bool
     */
    public function hasFillable()
    {
        return ! empty($this->fillable);
    }

    /**
     * Lấy danh sách fillable attributes.
     *
     * @return array<int, string>
     */
    public function getFillable()
    {
        return $this->fillable;
    }

    /**
     * Lấy blueprint gốc.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint
     */
    public function getBlueprint()
    {
        return $this->blueprint;
    }

    /**
     * Kiểm tra một Fluent command có đại diện cho primary key không.
     *
     * @param \Illuminate\Support\Fluent $command
     *
     * @return bool
     */
    public function isPrimaryKey(Fluent $command)
    {
        foreach ((array) $this->primaryKeys->columns as $column) {
            if (! in_array($column, $command->columns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra một Fluent command có phải unique key không.
     *
     * @param \Illuminate\Support\Fluent $command
     *
     * @return bool
     */
    public function isUniqueKey(Fluent $command)
    {
        return $this->blueprint->isUniqueKey($command);
    }

    /**
     * Model này có dùng base files (tách Base/User) không.
     *
     * @return bool
     */
    public function usesBaseFiles()
    {
        return $this->config('base_files', false);
    }

    /**
     * Có sinh constant cho từng property không.
     *
     * @return bool
     */
    public function usesPropertyConstants()
    {
        return $this->config('with_property_constants', false);
    }

    /**
     * Có sinh mảng columns trong model không.
     *
     * @return bool
     */
    public function usesColumnList()
    {
        return $this->config('with_column_list', false);
    }

    /**
     * Số space thay cho tab khi indent (0 = dùng tab).
     *
     * @return int
     */
    public function indentWithSpace()
    {
        return (int) $this->config('indent_with_space', 0);
    }

    /**
     * Có sinh field 'hints' trong model không.
     *
     * @return bool
     */
    public function usesHints()
    {
        return $this->config('hints', false);
    }

    /**
     * Ngược lại với usesBaseFiles().
     *
     * @return bool
     */
    public function doesNotUseBaseFiles()
    {
        return ! $this->usesBaseFiles();
    }

    /**
     * Lấy giá trị config theo context của blueprint hiện tại.
     *
     * @param string|null $key
     * @param mixed       $default
     *
     * @return mixed
     */
    public function config($key = null, $default = null)
    {
        return $this->factory->config($this->getBlueprint(), $key, $default);
    }

    /**
     * Fillable sẽ được đặt trong base files hay không.
     *
     * @return bool
     */
    public function fillableInBaseFiles(): bool
    {
        return $this->config('fillable_in_base_files', false);
    }

    /**
     * Hidden sẽ được đặt trong base files hay không.
     *
     * @return bool
     */
    public function hiddenInBaseFiles(): bool
    {
        return $this->config('hidden_in_base_files', false);
    }

    /**
     * Có sinh return type cho methods relations không.
     *
     * @return bool
     */
    public function definesReturnTypes()
    {
        return $this->definesReturnTypes;
    }
}
