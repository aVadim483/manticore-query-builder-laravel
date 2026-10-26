<?php
declare(strict_types=1);

/** Experimental tokenizer for profiling only. Never registered by the library. */
final class WriteNumericPrototype
{
    public static function prepare(string $sql,array $bindings):array
    {
        if(!array_filter($bindings,'is_float'))return [$sql,$bindings];
        foreach($bindings as $value)if(is_float($value)&&!is_finite($value)){
            throw new InvalidArgumentException('Manticore parameters must be finite numbers.');
        }
        $pattern=<<<'REGEX'
~
    '(?:[^'\\]++|\\(?:[\s\S]|\z)|'')*(?:'|\z)
  | "(?:[^"\\]++|\\(?:[\s\S]|\z)|"")*(?:"|\z)
  | \x60(?:[^\x60\\]++|\\(?:[\s\S]|\z)|\x60\x60)*(?:\x60|\z)
  | /\*[\s\S]*?(?:\*/|\z)
  | --(?=\s|\z)[^\n]*
  | \#[^\n]*
  | \?
  | (?<![a-zA-Z0-9_:]):[a-zA-Z_][a-zA-Z0-9_]*
~x
REGEX;
        $remaining=$bindings;$position=0;
        $result=preg_replace_callback($pattern,static function(array $match)use($bindings,&$remaining,&$position):string{
            $token=$match[0];
            if($token[0]==='?')$key=$position++;
            elseif($token[0]===':'){
                $name=substr($token,1);
                $key=array_key_exists($name,$bindings)?$name:$token;
            }else return $token;
            if(array_key_exists($key,$bindings)&&is_float($bindings[$key])){
                unset($remaining[$key]);
                return json_encode($bindings[$key],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
            }
            return $token;
        },$sql);
        if($result===null)throw new RuntimeException('Prototype tokenizer failed: '.preg_last_error_msg());
        return [$result,array_is_list($bindings)?array_values($remaining):$remaining];
    }
}
