<?php

namespace Connecttech\AutoRenderModels\Model;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Connecttech\AutoRenderModels\Meta\Blueprint;
use Connecttech\AutoRenderModels\Meta\SchemaManager;
use Connecttech\AutoRenderModels\Support\Classify;
use Connecttech\AutoRenderModels\Support\Dumper;
use Connecttech\AutoRenderModels\Model\Enum\Factory as EnumFactory;

/**
 * Class Factory
 *
 * Factory chịu trách nhiệm:
 * - Đọc schema & bảng từ database (thông qua SchemaManager)
 * - Sinh ra class Model (file PHP) tương ứng với từng table
 * - Tự xử lý template, namespace, import, trait, constant, field, relation,...
 * - Hỗ trợ tách BaseModel và UserModel (user file) nếu cấu hình yêu cầu.
 */
class Factory
{
    /**
     * Quản lý kết nối database của Laravel.
     *
     * @var \Illuminate\Database\DatabaseManager
     */
    private $db;

    /**
     * Quản lý schema/tables, được set sau khi gọi on().
     *
     * @var \Connecttech\AutoRenderModels\Meta\SchemaManager
     */
    protected $schemas = [];

    /**
     * Filesystem để đọc/ghi file (template, model...).
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Helper để sinh code (annotation, field, method, constant, mixin...).
     *
     * @var \Connecttech\AutoRenderModels\Support\Classify
     */
    protected $class;

    /**
     * Đối tượng Config dùng để truy xuất cấu hình sinh model.
     *
     * @var \Connecttech\AutoRenderModels\Model\Config
     */
    protected $config;

    /**
     * Quản lý cache & tạo instance Model thông qua Factory.
     *
     * @var \Connecttech\AutoRenderModels\Model\ModelManager
     */
    protected $models;

    /**
     * Danh sách Mutator được đăng ký để chỉnh sửa Model trước khi render.
     *
     * @var \Connecttech\AutoRenderModels\Model\Mutator[]
     */
    protected $mutators = [];

    /**
     * Factory để sinh PHP Enums.
     *
     * @var \Connecttech\AutoRenderModels\Model\Enum\Factory
     */
    protected EnumFactory $enumFactory;

    /**
     * ModelsFactory constructor.
     *
     * @param \Illuminate\Database\DatabaseManager              $db     Quản lý kết nối cơ sở dữ liệu.
     * @param \Illuminate\Filesystem\Filesystem                 $files  Đối tượng filesystem để thao tác file.
     * @param \Connecttech\AutoRenderModels\Support\Classify    $writer Helper sinh code (annotations, fields, methods...).
     * @param \Connecttech\AutoRenderModels\Model\Config        $config Cấu hình sinh model.
     * @param \Connecttech\AutoRenderModels\Model\Enum\Factory  $enumFactory Factory để sinh Enum classes.
     */
    public function __construct(
        DatabaseManager $db,
        Filesystem $files,
        Classify $writer,
        Config $config,
        EnumFactory $enumFactory
    )
    {
        $this->db = $db;
        $this->files = $files;
        $this->config = $config;
        $this->class = $writer;
        $this->enumFactory = $enumFactory;
    }

    /**
     * Tạo một Mutator mới và thêm vào danh sách mutators.
     *
     * Mutator cho phép can thiệp / chỉnh sửa Model trong quá trình build
     * (ví dụ: thêm field, sửa relation, chỉnh lại hint, v.v.)
     *
     * @return \Connecttech\AutoRenderModels\Model\Mutator Mutator vừa được tạo.
     */
    public function mutate()
    {
        return $this->mutators[] = new Mutator();
    }

    /**
     * Lấy ModelManager, khởi tạo nếu chưa có.
     *
     * @return \Connecttech\AutoRenderModels\Model\ModelManager
     */
    protected function models()
    {
        if (! isset($this->models)) {
            $this->models = new ModelManager($this);
        }

        return $this->models;
    }

    /**
     * Chọn connection để làm việc (schema, table...).
     *
     * @param string|null $connection Tên connection trong config database.php (null = default).
     *
     * @return $this
     */
    public function on($connection = null)
    {
        // Tạo SchemaManager cho connection tương ứng
        $this->schemas = new SchemaManager($this->db->connection($connection));

        return $this;
    }

    /**
     * Map toàn bộ bảng trong một schema: đọc schema, loop qua từng table và generate model.
     *
     * @param string $schema Tên schema (ví dụ: public, dbo, tên database...)
     *
     * @return void
     */
    public function map($schema)
    {
        if (! isset($this->schemas)) {
            // Nếu chưa chọn connection, dùng connection mặc định
            $this->on();
        }

        $mapper = $this->makeSchema($schema);

        // Generate enums for the schema first
        $this->enumFactory->generateEnums($schema);

        foreach ($mapper->tables() as $blueprint) {
            // Chỉ tạo model nếu không nằm trong danh sách except
            // và (nếu có only) thì phải match với pattern trong only
            if ($this->shouldTakeOnly($blueprint) && $this->shouldNotExclude($blueprint)) {
                $this->create($mapper->schema(), $blueprint->table());
            }
        }
    }

    /**
     * Kiểm tra bảng hiện tại có nằm trong danh sách "except" không.
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return bool true nếu KHÔNG bị exclude, false nếu bị loại.
     */
    protected function shouldNotExclude(Blueprint $blueprint)
    {
        foreach ($this->config($blueprint, 'except', []) as $pattern) {
            if (Str::is($pattern, $blueprint->table())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra xem có cấu hình "only" hay không,
     * và nếu có thì bảng hiện tại có match với bất kỳ pattern nào không.
     *
     * Không có cấu hình only => mặc định là lấy tất cả (true).
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return bool true nếu nên lấy, false nếu không match.
     */
    protected function shouldTakeOnly(Blueprint $blueprint)
    {
        if ($patterns = $this->config($blueprint, 'only', [])) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $blueprint->table())) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Tạo model file cho một bảng cụ thể trong schema.
     *
     * - Build đối tượng Model
     * - Load template
     * - Fill template
     * - Ghi file BaseModel
     * - Nếu dùng base files, generate thêm UserModel nếu cần
     *
     * @param string $schema Tên schema.
     * @param string $table  Tên bảng.
     *
     * @return void
     */
    public function create($schema, $table)
    {
        // Generate enums for the schema (in case it wasn't done for the whole DB)
        $this->enumFactory->generateEnums($schema);

        $model = $this->makeModel($schema, $table);
        $template = $this->prepareTemplate($model, 'model');

        $file = $this->fillTemplate($template, $model);

        // Chuyển tab thành space nếu model yêu cầu indent bằng space
        if ($model->indentWithSpace()) {
            $file = str_replace("\t", str_repeat(' ', $model->indentWithSpace()), $file);
        }

        // Ghi file BaseModel (hoặc model duy nhất nếu không dùng base files)
        $this->files->put($this->modelPath($model, $model->usesBaseFiles() ? ['Base'] : []), $file);

        // Nếu dùng base files và chưa có user file => tạo user file
        if ($this->needsUserFile($model)) {
            $this->createUserFile($model);
        }
    }

    /**
     * Tạo instance Model tương ứng với schema + table.
     *
     * @param string $schema        Tên schema.
     * @param string $table         Tên bảng.
     * @param bool   $withRelations Có build relations luôn hay không.
     *
     * @return \Connecttech\AutoRenderModels\Model\Model
     */
    public function makeModel($schema, $table, $withRelations = true)
    {
        return $this->models()->make($schema, $table, $this->mutators, $withRelations);
    }

    /**
     * Tạo đối tượng Schema cho schema name tương ứng.
     *
     * @param string $schema
     *
     * @return \Connecttech\AutoRenderModels\Meta\Schema
     */
    public function makeSchema($schema)
    {
        return $this->schemas->make($schema);
    }

    /**
     * Lấy danh sách các bảng tham chiếu tới Model hiện tại (foreign key reverse).
     *
     * TODO: phần tìm referencing nên được giao cho SchemaManager,
     * và phần build model tương ứng nên được giao cho ModelManager.
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return array Mảng thông tin reference, kèm blueprint & model tương ứng.
     */
    public function referencing(Model $model)
    {
        $references = [];

        // TODO: SchemaManager should do this
        foreach ($this->schemas as $schema) {
            $references = array_merge($references, $schema->referencing($model->getBlueprint()));
        }

        // TODO: ModelManager should do this
        foreach ($references as &$related) {
            $blueprint = $related['blueprint'];
            $related['model'] = $model->getBlueprint()->is($blueprint->schema(), $blueprint->table())
                ? $model
                : $this->makeModel($blueprint->schema(), $blueprint->table(), false);
        }

        return $references;
    }

    /**
     * Chuẩn bị nội dung template (model hoặc user_model).
     *
     * Ưu tiên:
     *  - Template custom trong config (*.template.{name})
     *  - Nếu không có thì dùng template mặc định trong thư mục Templates
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     * @param string                                     $name   Tên template (vd: "model", "user_model").
     *
     * @return string Nội dung template sau khi load.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function prepareTemplate(Model $model, $name)
    {
        $defaultFile = $this->path([__DIR__, 'Templates', $name]);
        $file = $this->config($model->getBlueprint(), "*.template.$name", $defaultFile);

        return $this->files->get($file);
    }

    /**
     * Điền dữ liệu vào template:
     * - namespace
     * - class name
     * - properties (annotations)
     * - parent class
     * - body (traits, fields, methods, relations, ...)
     * - imports (use ...)
     *
     * Đồng thời tự rút gọn FQN và build danh sách import cho model.
     *
     * @param string                                        $template Nội dung template gốc.
     * @param \Connecttech\AutoRenderModels\Model\Model     $model    Model metadata.
     *
     * @return string Nội dung file model hoàn chỉnh.
     */
    protected function fillTemplate($template, Model $model)
    {
        $template = str_replace('{{namespace}}', $model->getBaseNamespace(), $template);
        $template = str_replace('{{class}}', $model->getClassName(), $template);

        // Build phần @property và @property-relations
        $properties = $this->properties($model);
        $dependencies = $this->shortenAndExtractImportableDependencies($properties, $model);
        $template = str_replace('{{properties}}', $properties, $template);

        // Parent class (kế thừa từ class nào)
        $parentClass = $model->getParentClass();
        $dependencies = array_merge($dependencies, $this->shortenAndExtractImportableDependencies($parentClass, $model));
        $template = str_replace('{{parent}}', $parentClass, $template);

        // Body class (traits, const, fields, relations,...)
        $body = $this->body($model);
        $dependencies = array_merge($dependencies, $this->shortenAndExtractImportableDependencies($body, $model));
        $template = str_replace('{{body}}', $body, $template);

        // Chuẩn bị danh sách imports (use ...)
        $imports = $this->imports(array_keys($dependencies), $model);
        $template = str_replace('{{imports}}', $imports, $template);

        return $template;
    }

    /**
     * Sinh phần import (use ...) cho model dựa trên danh sách dependencies.
     *
     * - Bỏ qua class trùng với chính UserModel
     * - Bỏ qua class cùng namespace với model
     * - Sort theo alphabet cho gọn
     *
     * @param array $dependencies Danh sách tên class đầy đủ (FQN).
     * @param Model $model        Model hiện tại.
     *
     * @return string Chuỗi "use xxx;" mỗi dòng.
     */
    private function imports($dependencies, Model $model)
    {
        $imports = [];
        foreach ($dependencies as $dependencyClass) {
            // Skip khi cùng class với user model (tránh import chính nó)
            if (trim($dependencyClass, "\\") == trim($model->getQualifiedUserClassName(), "\\")) {
                continue;
            }

            // Không import các class cùng namespace
            $inCurrentNamespacePattern = str_replace('\\', '\\\\', "/{$model->getBaseNamespace()}\\[a-zA-Z0-9_]*/");
            if (preg_match($inCurrentNamespacePattern, $dependencyClass)) {
                continue;
            }

            $imports[] = "use {$dependencyClass};";
        }

        sort($imports);

        return implode("\n", $imports);
    }

    /**
     * Tìm tất cả các Fully-Qualified Class Name (FQN) trong placeholder,
     * rồi:
     *  - Lưu lại danh sách FQN để import
     *  - Thay thế trong nội dung placeholder bằng tên class rút gọn
     *
     * @param string                                            $placeholder Tham chiếu tới nội dung (properties, parent, body...).
     * @param \Connecttech\AutoRenderModels\Model\Model         $model       Model hiện tại.
     *
     * @return array Mảng FQN (key là FQN, value = true).
     */
    private function shortenAndExtractImportableDependencies(&$placeholder, $model)
    {
        $qualifiedClassesPattern = '/([\\\\a-zA-Z0-9_]*\\\\[\\\\a-zA-Z0-9_]*)/';
        $matches = [];
        $importableDependencies = [];

        if (preg_match_all($qualifiedClassesPattern, $placeholder, $matches)) {
            foreach ($matches[1] as $usedClass) {
                $namespacePieces = explode('\\', $usedClass);
                $className = array_pop($namespacePieces);

                /**
                 * Tránh phá vỡ quan hệ same-model khi sử dụng base classes.
                 *
                 * @see https://github.com/Connecttech/laravel/issues/209
                 */
                if ($model->usesBaseFiles() && $usedClass === $model->getQualifiedUserClassName()) {
                    continue;
                }

                // Khi trùng tên class nhưng khác namespace với model => bỏ qua
                if (
                    $className == $model->getClassName() &&
                    trim(implode('\\', $namespacePieces), '\\') != trim($model->getNamespace(), '\\')
                ) {
                    continue;
                }

                // Ghi nhận dependency để import
                $importableDependencies[trim($usedClass, '\\')] = true;

                // Thay FQN trong nội dung bằng tên class ngắn
                $placeholder = preg_replace('!' . addslashes($usedClass) . '\b!', addslashes($className), $placeholder, 1);
            }
        }

        return $importableDependencies;
    }

    /**
     * Build phần annotation @property cho model:
     * - @property cho các field (columns)
     * - @property cho các quan hệ (relations) nếu tên không trùng với field
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return string Chuỗi annotation (block comment) cho phần properties.
     */
    protected function properties(Model $model)
    {
        // Process property annotations
        $annotations = '';

        foreach ($model->getProperties() as $name => $hint) {
            $annotations .= $this->class->annotation('property', "$hint \$$name");
        }

        if ($model->hasRelations()) {
            // Ngăn cách properties và relations bằng một dòng trống trong docblock
            $annotations .= "\n * ";
        }

        foreach ($model->getRelations() as $name => $relation) {
            // TODO: handle collision, có thể phải rename relation nếu trùng field
            if ($model->hasProperty($name)) {
                continue;
            }

            $annotations .= $this->class->annotation('property', $relation->hint() . " \$$name");
        }

        return $annotations;
    }

    /**
     * Build phần thân class (body) cho model:
     * - Traits
     * - Constants (CREATED_AT, UPDATED_AT, DELETED_AT, constants cho fields)
     * - Các field như connection, table, primaryKey, timestamps, casts, hidden, fillable...
     * - Các methods mutation & relations
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return string Nội dung phần body của class.
     */
    protected function body(Model $model)
    {
        $body = '';

        // Thêm traits
        foreach ($model->getTraits() as $trait) {
            $body .= $this->class->mixin($trait);
        }

        $excludedConstants = [];

        // Các constant cho custom timestamp fields
        if ($model->hasCustomCreatedAtField()) {
            $body .= $this->class->constant('CREATED_AT', $model->getCreatedAtField());
            $excludedConstants[] = $model->getCreatedAtField();
        }

        if ($model->hasCustomUpdatedAtField()) {
            $body .= $this->class->constant('UPDATED_AT', $model->getUpdatedAtField());
            $excludedConstants[] = $model->getUpdatedAtField();
        }

        if ($model->hasCustomDeletedAtField()) {
            $body .= $this->class->constant('DELETED_AT', $model->getDeletedAtField());
            $excludedConstants[] = $model->getDeletedAtField();
        }

        // Constant cho từng property (nếu bật usesPropertyConstants)
        if ($model->usesPropertyConstants()) {
            $properties = array_keys($model->getProperties());
            $properties = array_diff($properties, $excludedConstants);

            foreach ($properties as $property) {
                $constantName = Str::upper(Str::snake($property));
                $body .= $this->class->constant($constantName, $property);
            }
        }

        $body = trim($body, "\n");

        // Nếu có constant / trait, thêm dòng trống sau đó trước khi tới fields
        if (! empty($body)) {
            $body .= "\n";
        }

        // Append connection name khi cần
        if ($model->shouldShowConnection()) {
            $body .= $this->class->field('connection', $model->getConnectionName());
        }

        // Nếu table không phải dạng plural mặc định => set $table
        if ($model->needsTableName()) {
            $body .= $this->class->field('table', $model->getTableForQuery());
        }

        if ($model->hasCustomPrimaryKey()) {
            $body .= $this->class->field('primaryKey', $model->getPrimaryKey());
        }

        if ($model->doesNotAutoincrement()) {
            $body .= $this->class->field('incrementing', false, ['visibility' => 'public']);
        }

        if ($model->hasCustomPerPage()) {
            $body .= $this->class->field('perPage', $model->getPerPage());
        }

        if (! $model->usesTimestamps()) {
            $body .= $this->class->field('timestamps', false, ['visibility' => 'public']);
        }

        if ($model->hasCustomDateFormat()) {
            $body .= $this->class->field('dateFormat', $model->getDateFormat());
        }

        if ($model->doesNotUseSnakeAttributes()) {
            $body .= $this->class->field('snakeAttributes', false, ['visibility' => 'public static']);
        }

        // Nếu cấu hình dùng danh sách columns
        if ($model->usesColumnList()) {
            $properties = array_keys($model->getProperties());

            $body .= "\n";
            $body .= $this->class->field('columns', $properties);
        }

        if ($model->hasCasts()) {
            if ($this->config($model->getBlueprint(), 'casts_style') === 'method') {
                $castsBody = 'return ' . Dumper::export($model->getCasts()) . ';';
                $body .= $this->class->method('casts', $castsBody, [
                    'visibility' => 'protected',
                    'returnType' => 'array',
                    'before' => "\n"
                ]);
            } else {
                $body .= $this->class->field('casts', $model->getCasts(), ['before' => "\n"]);
            }
        }

        if ($model->hasHidden() && ($model->doesNotUseBaseFiles() || $model->hiddenInBaseFiles())) {
            $body .= $this->class->field('hidden', $model->getHidden(), ['before' => "\n"]);
        }

        if ($model->hasFillable() && ($model->doesNotUseBaseFiles() || $model->fillableInBaseFiles())) {
            $body .= $this->class->field('fillable', $model->getFillable(), ['before' => "\n"]);
        }

        if ($model->hasHints() && $model->usesHints()) {
            $body .= $this->class->field('hints', $model->getHints(), ['before' => "\n"]);
        }

        // Methods từ mutation
        foreach ($model->getMutations() as $mutation) {
            $body .= $this->class->method($mutation->name(), $mutation->body(), ['before' => "\n"]);
        }

        // Methods quan hệ
        foreach ($model->getRelations() as $constraint) {
            $body .= $this->class->method(
                $constraint->name(),
                $constraint->body(),
                [
                    'before' => "\n",
                    'returnType' => $model->definesReturnTypes() ? $constraint->returnType() : null,
                ]
            );
        }

        // Làm sạch \n thừa ở đầu/cuối
        $body = trim($body, "\n");

        return $body;
    }

    /**
     * Tính đường dẫn file model (Base hoặc User) và đảm bảo thư mục tồn tại.
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     * @param array                                     $custom Thêm các folder con (vd: ['Base']).
     *
     * @return string Đường dẫn đầy đủ tới file model.
     */
    protected function modelPath(Model $model, $custom = [])
    {
        $modelsDirectory = $this->path(array_merge([$this->config($model->getBlueprint(), 'path')], $custom));

        if (! $this->files->isDirectory($modelsDirectory)) {
            $this->files->makeDirectory($modelsDirectory, 0755, true);
        }

        return $this->path([$modelsDirectory, $model->getClassName() . '.php']);
    }

    /**
     * Join các thành phần path lại bằng DIRECTORY_SEPARATOR.
     *
     * @param array|string $pieces
     *
     * @return string
     */
    protected function path($pieces)
    {
        return implode(DIRECTORY_SEPARATOR, (array) $pieces);
    }

    /**
     * Kiểm tra xem có cần tạo user file hay không.
     *
     * - Chỉ tạo user file khi:
     *   + Chưa tồn tại file user model
     *   + Model đang dùng base files (tách Base/User)
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return bool
     */
    public function needsUserFile(Model $model)
    {
        return ! $this->files->exists($this->modelPath($model)) && $model->usesBaseFiles();
    }

    /**
     * Tạo file UserModel (file user override) kế thừa từ BaseModel.
     *
     * - Load template user_model
     * - Thay namespace, class, imports, parent, body
     * - Ghi ra file user
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function createUserFile(Model $model)
    {
        $file = $this->modelPath($model);

        $template = $this->prepareTemplate($model, 'user_model');
        $template = str_replace('{{namespace}}', $model->getNamespace(), $template);
        $template = str_replace('{{class}}', $model->getClassName(), $template);
        $template = str_replace('{{imports}}', $this->formatBaseClasses($model), $template);
        $template = str_replace('{{parent}}', $this->getBaseClassName($model), $template);
        $template = str_replace('{{body}}', $this->userFileBody($model), $template);

        $this->files->put($file, $template);
    }

    /**
     * Tạo dòng import cho BaseModel trong UserModel.
     *
     * Ví dụ:
     * use App\Models\Base\User as BaseUser;
     *
     * @param Model $model
     *
     * @return string
     */
    private function formatBaseClasses(Model $model)
    {
        return "use {$model->getBaseNamespace()}\\{$model->getClassName()} as {$this->getBaseClassName($model)};";
    }

    /**
     * Lấy tên class BaseModel tương ứng (Base + ClassName).
     *
     * @param Model $model
     *
     * @return string
     */
    private function getBaseClassName(Model $model)
    {
        return 'Base' . $model->getClassName();
    }

    /**
     * Build phần body cho UserModel (file user),
     * chỉ override các field như hidden, fillable nếu chúng không được
     * định nghĩa trong base files.
     *
     * @param \Connecttech\AutoRenderModels\Model\Model $model
     *
     * @return string
     */
    protected function userFileBody(Model $model)
    {
        $body = '';

        if ($model->hasHidden() && !$model->hiddenInBaseFiles()) {
            $body .= $this->class->field('hidden', $model->getHidden());
        }

        if ($model->hasFillable() && !$model->fillableInBaseFiles()) {
            $body .= $this->class->field('fillable', $model->getFillable(), ['before' => "\n"]);
        }

        // Dọn line break thừa ở đầu/cuối body
        $body = ltrim(rtrim($body, "\n"), "\n");

        return $body;
    }

    /**
     * Truy cập config:
     * - Nếu không truyền Blueprint: trả về chính đối tượng Config (để dùng tiếp).
     * - Nếu có Blueprint + key: dùng Config::get() để lấy giá trị theo context.
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint|null $blueprint Blueprint của model (connection/schema/table).
     * @param string|null                                       $key       Tên key cần lấy.
     * @param mixed                                             $default   Giá trị mặc định nếu không có.
     *
     * @return mixed|\Connecttech\AutoRenderModels\Model\Config
     */
    public function config(?Blueprint $blueprint = null, $key = null, $default = null)
    {
        if (is_null($blueprint)) {
            // Trả về đối tượng Config để call tiếp ->get(...)
            return $this->config;
        }

        return $this->config->get($blueprint, $key, $default);
    }
}
