<?php
require dirname(__DIR__,2).'/tests/native-bootstrap.php';
require __DIR__.'/prototype.php';
if(class_exists('avadim\\Manticore\\Laravel\\Database\\NumericBindings',false))throw new RuntimeException('NumericBindings already loaded');
class_alias(WriteNumericPrototype::class,'avadim\\Manticore\\Laravel\\Database\\NumericBindings');
