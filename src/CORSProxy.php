<?php

namespace Elfo404\LaravelCORSProxy;

use GuzzleHttp\Exception\ClientException as ClientException;
use GuzzleHttp\Psr7\Uri as Uri;
use Illuminate\Http\Request as Request;
use GuzzleHttp\Client as Client;
use \GuzzleHttp\Psr7\Request as Req;
use \GuzzleHttp\Psr7\Response as Res;
use \GuzzleHttp\Psr7\BufferStream;

class CORSProxy {

    public static function index(Request $request) {
        $pathUri = preg_replace('#/proxy/(https?)/#', '$1://', $request->getPathInfo());
        $schemeAndHost = preg_replace('#https?:#', '', $request->getSchemeAndHttpHost());
        $prefix = $schemeAndHost.preg_replace('#(/proxy/https?/[^/]+).*#', '$1', $request->getRequestUri());
        $uri = new Uri($request->header(config('cors-proxy.header_name', 'X-Proxy-To'), $pathUri));
        $proxiedUri = $uri->__toString();

        if (strpos($proxiedUri, $schemeAndHost) > 0) {
            return new Res(301, [ 'location' => $proxiedUri.'?'.$request->getQueryString() ]);
        }

        $validRequests = array_map(
            function ($expr) {
                if (strpos($expr, '/') === 0) {
                    return $expr;
                } else {
                    return '/'.preg_replace('/\//', '\\/', $expr).'/';
                }
            }, 
            config('cors-proxy.valid_requests')
        );

        $requestHandlers = config('cors-proxy.request_handlers', []);

        if (preg_replace($validRequests, '', $proxiedUri) != $proxiedUri) {
            $requestHandler = array_filter(array_keys($requestHandlers), function ($expr) use ($proxiedUri) {
                return preg_replace($expr, '', $proxiedUri) != $proxiedUri;
            });
            if (count($requestHandler) == 1) {
                $requestHandler = $requestHandlers[$requestHandler[0]];
                $content = $requestHandler($proxiedUri, $request);

                return new Res(200, [], $content);
            }

            $allowsRedirects = config('cors-proxy.allow_redirects', true);
            $client = new Client([
                'base_uri' => $uri->getScheme() . "://" . $uri->getHost(),
                'proxy' => [
                    'http' => config('cors-proxy.http_proxy', false),
                    'https' => config('cors-proxy.https_proxy', false)
                ],
                'timeout' => config('cors-proxy.timeout', 2),
                'allow_redirects' => $allowsRedirects,
                'http_errors' => false,
                'verify' => false
            ]);
            $request->headers->remove('host');
            $request->headers->remove('origin');
            $request->headers->remove('accept-encoding');

            if (isset($_REQUEST['skipCookies']) && 
                $_REQUEST['skipCookies'] == 'true' ||
                isset($_COOKIE['skipCookies']) && 
                ($_COOKIE['skipCookies'] == 'true' || 
                 $_COOKIE['skipCookies'] == $_SERVER['QUERY_STRING']
                )) {
                $request->headers->remove('cookie');

                unset($_COOKIE['skipCookies']);
                setcookie('skipCookies', null, -1);
            }

            $req = new Req($request->method(), $uri->getPath(), $request->headers->all(), $request->method() == 'GET' ? null : $request->getContent(true));
            try {
                $res = $client->send($req, ['query' => $request->getQueryString()]);
                $isRedirect = $res->getStatusCode() >= 300 && $res->getStatusCode() < 400;

                if ($isRedirect) {
                    $location = $res->getHeader('location')[0];
                    $location = str_replace('.com', '.app', $location);
                    if (strpos($location, '/') === 0) {
                        $location = $prefix.$location;
                    } else if (strpos($location, 'http') === 0) {
                        $location = '/proxy/'.preg_replace('#://#', '/', $location);
                    }
                    $res = $res->withHeader('location', $location);
                }

                if (!$allowsRedirects && $isRedirect) {
                    $res = $res->withStatus(303);
                }

                $res = $res->withoutHeader('transfer-encoding');

                $domain = $uri->getScheme().'://'.$uri->getAuthority();

                $exclusionExpr = '(?!https?:|data:|javascript:|#|\')';
                $proxyHrefs = str_replace('/', '\/', implode('|', config('cors-proxy.proxy_hrefs', [])));
                $proxyLazyHrefs = str_replace('/', '\/', implode('|', config('cors-proxy.proxy_lazy_hrefs', ['.'])));
                $proxyStringHrefs = str_replace('/', '\/', implode('|', config('cors-proxy.proxy_string_hrefs', [])));
                $bypassExpr = '(?!'.$proxyHrefs.')';
                $forceProxyExpr = '(?='.$proxyHrefs.')';

                $rep['/href="'.$exclusionExpr.$bypassExpr.'/']      = 'href="'.$domain;
                $rep['/href="'.$exclusionExpr.$forceProxyExpr.'/']  = 'href="'.$prefix;
                $rep['/src="'.$exclusionExpr.$bypassExpr.'/']       = 'src="'.$domain;
                $rep['/src="'.$exclusionExpr.$forceProxyExpr.'/']   = 'src="'.$prefix;
                $rep['/@import[\n+\s+]"\//'] = '@import "'.$domain;
                $rep['/@import[\n+\s+]"\./'] = '@import "'.$domain;
                $rep['/@font-face \{/'] = "$0\n  font-display: fallback; ";
                                    
                $rep['/location.protocol\+"\/\/"\+location.host\+/'] = '(/'.$proxyLazyHrefs.'/.test(e) ? "'.$prefix.'" : "'.$domain.'")+';
                if (strlen($proxyStringHrefs) > 0) {
                    $rep['/"('.$proxyStringHrefs.')"/'] = '"'.$prefix.'$1"';
                }

                $content = preg_replace(
                    array_keys($rep),
                    array_values($rep),
                    $res->getBody()->getContents()
                );

                $body = new BufferStream();
                $body->write($content);
                $res = $res->withBody($body);
            } catch (ClientException $e) {
                $res = $e->getResponse();
            }
            return $res;
        } else {
            throw new InvalidRequestException($uri);
        }
    }
}