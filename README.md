# Laravel Cors Proxy

 A simple CORS Proxy for laravel applications 
 
 
## Installation

Add the package to composer.json:

```json
"require": {
	...
	"elfo404/laravel-cors-proxy": "0.2.0"
},
```
	
Or from the command line:
`` composer require elfo404/laravel-cors-proxy``
	 	

Add `Elfo404\LaravelCORSProxy\CORSProxyServiceProvider::class` in `config\app.php`, providers section.
	
	
There is no need to add a Facade.

You have now an endpoint for `http[s]://example.com/proxy` to submit your CORS proxied requests.


## Configuration


Run `php artisan vendor:publish --provider="Elfo404\LaravelCORSProxy\CORSProxyServiceProvider" --tag=config --force` to export the default configuration in `config/cors-proxy.php`:

```php
<?php
return [
    'header_name'=>'X-Proxy-To',
    'valid_requests'=>[],
    'proxy_hrefs'=>[],
    'proxy_lazy_hrefs'=>[],
    'proxy_string_hrefs'=>[],
    'request_handlers'=>[],
    'http_proxy'=>false,
    'https_proxy'=>false,
    'allow_redirects'=>true,
    'timeout'=>2,
];
```
- `header_name` is the default header name conaining the URI you want to proxy. Every request must either send this header or use the `/proxy/scheme/host/path` notation to specify which URI the request has to be sent to.
- `valid_requests` array containing a list of URIs (or matching RegExps) that can be proxied. this is only for security reason as these endpoints ar publicly accessible.
- `proxy_hrefs` array containing a list of RegExps to match in the body of the proxied response and replace by proxied URIs (`href`, `src` and `@import` attributes are searched). by default FQDN URIs do not get proxied, only relative/absolute ones, this may be used to filter further to avoid proxying all resources.
- `proxy_lazy_hrefs` similar to `proxy_hrefs` but defines which lazy loaded resources (ie: using jQuery xLazyLoader or similar).
- `proxy_string_hrefs` similar to the previous config entries but only applies to hardcoded js strings (ie: "/path").
- `request_handlers` providers and interface to configure custom request handlers for proxied URIs used to respond with alternative content to a given matching resource (based on given RegExps provided as key as a function that takes `$proxiedUri` and `$request` and responds with the body to reply with as opposed to the proxied resource response).
- `http_proxy` and `https_proxy` are self explanatory, improved doc is coming soon.
- `allow_redirects` allows the HTTP client to follow redirects automatically for proxied HTTP 300 range responses. Will proxy redirected URIs (if they are not FQDN ones).
- `timeout` controls the request timeout while connecting to the proxied resource.

## Usage

- jQuery example (domainA.com) - using the `X-Proxy-To` HTTP Header:
```javascript
$.ajax({
  type: "POST",
  beforeSend: function(request) {
    request.setRequestHeader("X-Proxy-To", 'http://domainB.com/api');
  },
  url: "http://domainA.com/proxy",
  data: ...
});
```

- jQuery example (domainA.com) - using the `/proxy` route:
```javascript
$.ajax({
  type: "POST",
  url: "http://domainA.com/proxy/http/domainB.com/api",
  data: ...
});
```
