<?php

namespace Ts;

class HttpServer
{

	public static function sendHeaders(): void
	{
		// Sin estos headers, las solicituded de HttpClient en .NET generan
		// HttpRequestException "TypeError: Failed to fetch".

		if (isset($_SERVER['HTTP_ORIGIN']))
		{
			header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
		}

		header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');

		header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

		if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS')
		{
			exit;
		}
	}
}
