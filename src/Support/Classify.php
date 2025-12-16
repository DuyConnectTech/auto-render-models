<?php

namespace Connecttech\AutoRenderModels\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Class Classify
 *
 * Helper chuyên để generate code PHP dạng string:
 * - annotation(): sinh dòng @property, @method... trong PHPDoc
 * - constant(): sinh hằng số class
 * - field(): sinh thuộc tính class (public/protected/static...)
 * - method(): sinh method (kèm visibility, return type)
 * - mixin(): sinh dòng use Trait;
 *
 * Lớp này là “bộ format” chính cho Factory khi build file model.
 */
class Classify
{
    /**
     * Sinh một dòng annotation trong PHPDoc.
     *
     * Ví dụ:
     *  - annotation('property', 'string $name')
     *    => "\n * @property string $name"
     *
     * @param string $name  Tên annotation (property, method, mixin,...).
     * @param string $value Nội dung phía sau annotation.
     *
     * @return string Chuỗi annotation, đã có "\n * " ở đầu.
     */
    public function annotation($name, $value)
    {
        return "\n * @$name $value";
    }

    /**
     * Sinh code constant cho class.
     *
     * Ví dụ:
     *  - constant('STATUS_ACTIVE', 'active')
     *    => "\tconst STATUS_ACTIVE = 'active';\n"
     *
     * @param string $name  Tên constant.
     * @param mixed  $value Giá trị constant, sẽ được Dumper::export() sang PHP code.
     *
     * @return string
     */
    public function constant($name, $value)
    {
        $value = Dumper::export($value);

        return "\tconst $name = $value;\n";
    }

    /**
     * Sinh code cho một field (property) trong class.
     *
     * Ví dụ:
     *  - field('table', 'users')
     *    => "\tprotected \$table = 'users';\n"
     *
     * Options hỗ trợ:
     *  - before     : chuỗi đặt trước dòng field (thường là "\n" để cách block)
     *  - visibility : public|protected|private|public static|protected static|...
     *  - after      : chuỗi đặt sau field (mặc định "\n")
     *
     * @param string $name    Tên thuộc tính.
     * @param mixed  $value   Giá trị, sẽ được Dumper::export().
     * @param array  $options Tuỳ chọn định dạng.
     *
     * @return string
     */
    public function field($name, $value, $options = [])
    {
        $value = Dumper::export($value);
        $before = Arr::get($options, 'before', '');
        $visibility = Arr::get($options, 'visibility', 'protected');
        $after = Arr::get($options, 'after', "\n");

        return "$before\t$visibility \$$name = $value;$after";
    }

    /**
     * Sinh code cho một method trong class.
     *
     * Ví dụ:
     *  - method('getName', 'return $this->name;')
     *
     * Options:
     *  - visibility : public|protected|private (mặc định 'public')
     *  - returnType : kiểu trả về, không có thì bỏ trống (vd: '\Illuminate\Support\Collection')
     *  - docblock   : nội dung docblock (string hoặc array lines)
     *
     * Output dạng:
     *  \t/**
     *  \t * @return string
     *  \t * /
     *  \tpublic function getName(): string
     *  \t{
     *  \t\treturn $this->name;
     *  \t}
     *
     * @param string $name    Tên method.
     * @param string $body    Nội dung thân method (1 dòng, đã là code PHP).
     * @param array  $options Tuỳ chọn (visibility, returnType, docblock).
     *
     * @return string
     */
    public function method($name, $body, $options = [])
    {
        $visibility = Arr::get($options, 'visibility', 'public');
        $returnType = Arr::get($options, 'returnType', null);
        $docblock   = Arr::get($options, 'docblock', null);
        $formattedReturnType = $returnType ? ': ' . $returnType : '';

        $code = "\n";

        if ($docblock) {
            $code .= "\t/**\n";
            foreach ((array) $docblock as $line) {
                $code .= "\t * $line\n";
            }
            $code .= "\t */\n";
        }

        $code .= "\t$visibility function $name()$formattedReturnType\n\t{\n\t\t$body\n\t}\n";

        return $code;
    }

    /**
     * Sinh code use Trait cho trong thân class.
     *
     * Ví dụ:
     *  - mixin('\Illuminate\Database\Eloquent\SoftDeletes')
     *    => "\tuse \Illuminate\Database\Eloquent\SoftDeletes;\n"
     *
     * @param string $class Tên class/trait (FQN hoặc không).
     *
     * @return string
     */
    public function mixin($class)
    {
        if (Str::startsWith($class, '\\')) {
            $class = Str::replaceFirst('\\', '', $class);
        }

        return "\tuse \\$class;\n";
    }
}
