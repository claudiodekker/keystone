<?php

namespace ClaudioDekker\Keystone\Actions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
class RespondToSudoRequired
{
    /**
     * Respond to a signed-in request the sudo gate refused: 403 for a JSON client, else the sudo show step.
     */
    public function handle(Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => __('keystone::messages.sudo_required'),
                'reason' => 'sudo',
            ], 403);
        }

        return redirect()->route('sudo');
    }
}
