<?php
namespace App\Traits;
use Carbon\Carbon;

trait ApiResponser
{
	/**
	 * Return a success JSON response.
	 *
	 * @param  array|string  $data
	 * @param  string  $message
	 * @param  int|null  $code
	 * @return \Illuminate\Http\JsonResponse
	 */
	protected function success($data='', string $message = "", int $code = 200)
	{
		return response()->json([
			'status' => 'success',
			'message' => $message,
			'data' => $data
		], $code);
	}

	/**
	 * Return an error JSON response.
	 *
	 * @param  string  $message
	 * @param  int  $code
	 * @param  array|string|null  $data
	 * @return \Illuminate\Http\JsonResponse
	 */
	protected function error(string $message = "", $data = null ,int $code=401)
	{
		return response()->json([
			'status' => 'error',
			'message' => $message,
			'data' => $data
		], $code);
	}
	protected function notLogin(int $code=200)
	{
		return response()->json([
			'status' => 'login',
			'message' => "Session expired."
		], $code);
	}
	protected function notFound(int $code=200)
	{
		return response()->json([
			'status' => 'not-found',
			'message' => ""
		], $code);
	}
}