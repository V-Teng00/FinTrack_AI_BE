<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskChatRequest;
use App\Services\ChatQueryService;

class ChatController extends Controller
{
    public function ask(AskChatRequest $request)
    {
        $service = new ChatQueryService($request->user()->id);

        $reply = $service->ask($request->message, $request->month);

        return response()->json(['reply' => $reply]);
    }
}
