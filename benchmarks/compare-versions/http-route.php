<?php
Illuminate\Support\Facades\Route::get('/__driver_benchmark', function (Illuminate\Http\Request $request) {
    require_once base_path('verification/ab/adapter.php');
    $config=json_decode(file_get_contents(base_path('verification/ab/config.json')),true,512,JSON_THROW_ON_ERROR);
    $scenario=$request->query('scenario','match_meta20');
    abort_unless(in_array($scenario,['match_meta20','structured1000'],true),400);
    $driver=new DriverComparison($config['client'],$config);
    ComparisonStatement::$executions=0;
    $value=$driver->normalize($scenario,$driver->run($scenario));
    return response()->json($value)->header('X-AB-Run',$config['run_id'])
        ->header('X-AB-Queries',(string)ComparisonStatement::$executions);
});
