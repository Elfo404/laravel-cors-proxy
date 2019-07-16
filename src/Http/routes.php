<?php


Route::any('/proxy','Elfo404\LaravelCORSProxy\Http\CORSController@index')->name('cors-proxy');
Route::any('/proxy/{any}','Elfo404\LaravelCORSProxy\Http\CORSController@index')->where('any', '.*')->name('cors-proxy');