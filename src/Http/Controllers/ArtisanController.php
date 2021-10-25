<?php

namespace Firevel\Artisan\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use App\Http\Requests\ArtisanRequest;
use Illuminate\Routing\Controller;

class ArtisanController extends Controller
{
    /**
     * Execute artisan command.
     *
     * @param  ArtisanRequest $request
     * @return Response
     */
    public function call(ArtisanRequest $request)
    {
	    Artisan::call($request->getContent());

	    return Artisan::output();
    }

    /**
     * Execute artisan command.
     *
     * @param  ArtisanRequest $request
     * @return Response
     */
    public function queue(ArtisanRequest $request)
    {
	    Artisan::queue($request->getContent())
	    	->onConnection(config('artisan.connection'))
	    	->onQueue(config('artisan.queue'));

	    return response()->json(['result' => 'success'], 200);;
    }

}
