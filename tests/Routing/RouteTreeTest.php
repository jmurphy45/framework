<?php

namespace Illuminate\Tests\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteTree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RouteTreeTest extends TestCase
{
    // -----------------------------------------------------------------------
    // RouteTree unit tests
    // -----------------------------------------------------------------------

    public function testStaticRouteIsReturned()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'users', []);
        $tree->add($route);

        $candidates = $tree->getCandidates('users');

        $this->assertContains($route, $candidates);
    }

    public function testParameterRouteIsReturned()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'users/{id}', []);
        $tree->add($route);

        $candidates = $tree->getCandidates('users/42');

        $this->assertContains($route, $candidates);
    }

    public function testStaticRouteNotReturnedForWrongPath()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'users', []);
        $tree->add($route);

        $this->assertEmpty($tree->getCandidates('posts'));
        $this->assertEmpty($tree->getCandidates('users/extra'));
    }

    public function testParameterRouteNotReturnedForShortPath()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'users/{id}', []);
        $tree->add($route);

        $this->assertEmpty($tree->getCandidates('users'));
    }

    public function testStaticAndParameterRoutesBothReturnedWhenPathMatches()
    {
        $tree = new RouteTree;
        $staticRoute = new Route('GET', 'users/profile', []);
        $paramRoute = new Route('GET', 'users/{id}', []);
        $tree->add($staticRoute);
        $tree->add($paramRoute);

        $candidates = $tree->getCandidates('users/profile');

        $this->assertContains($staticRoute, $candidates);
        $this->assertContains($paramRoute, $candidates);
    }

    public function testStaticMatchComesBeforeParamMatch()
    {
        $tree = new RouteTree;
        // Register param route first — static should still surface first in candidates.
        $paramRoute = new Route('GET', 'users/{id}', []);
        $staticRoute = new Route('GET', 'users/profile', []);
        $tree->add($paramRoute);
        $tree->add($staticRoute);

        $candidates = $tree->getCandidates('users/profile');

        $this->assertSame($staticRoute, $candidates[0]);
        $this->assertSame($paramRoute, $candidates[1]);
    }

    public function testOptionalParamMatchesWithSegment()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'posts/{slug?}', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates('posts/hello-world'));
    }

    public function testOptionalParamMatchesWithoutSegment()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'posts/{slug?}', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates('posts'));
    }

    public function testOptionalParamDeduplicatesCandidates()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'posts/{slug?}', []);
        $tree->add($route);

        // Route must appear exactly once even though optional paths insert it twice.
        $candidates = $tree->getCandidates('posts');

        $count = count(array_filter($candidates, function ($r) use ($route) {
            return $r === $route;
        }));

        $this->assertSame(1, $count);
    }

    public function testWildcardRouteMatchesSingleRemainingSegment()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'files/{path+}', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates('files/readme.txt'));
    }

    public function testWildcardRouteMatchesMultipleRemainingSegments()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'files/{path+}', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates('files/a/b/c'));
    }

    public function testMultipleNestedParameterSegments()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'api/v1/users/{user}/posts/{post}', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates('api/v1/users/5/posts/99'));
    }

    public function testMultipleNestedParameterSegmentsNotReturnedForShortPath()
    {
        $tree = new RouteTree;
        $route = new Route('GET', 'api/v1/users/{user}/posts/{post}', []);
        $tree->add($route);

        $this->assertEmpty($tree->getCandidates('api/v1/users/5'));
        $this->assertEmpty($tree->getCandidates('api/v1/users/5/posts'));
    }

    public function testRootRouteMatchesEmptyPath()
    {
        $tree = new RouteTree;
        $route = new Route('GET', '/', []);
        $tree->add($route);

        $this->assertContains($route, $tree->getCandidates(''));
    }

    public function testUnrelatedRoutesAreNotReturned()
    {
        $tree = new RouteTree;
        $tree->add(new Route('GET', 'users', []));
        $tree->add(new Route('GET', 'users/{id}', []));
        $tree->add(new Route('GET', 'users/{id}/posts', []));
        $unrelated = new Route('GET', 'products', []);
        $tree->add($unrelated);

        // None of the "users" routes should appear when looking for "products".
        foreach ($tree->getCandidates('users') as $candidate) {
            $this->assertNotSame($unrelated, $candidate);
        }

        // The "users" routes must not appear in a "products" lookup.
        $this->assertContains($unrelated, $tree->getCandidates('products'));
        $this->assertCount(1, $tree->getCandidates('products'));
    }

    // -----------------------------------------------------------------------
    // RouteCollection integration tests (tree-backed matching)
    // -----------------------------------------------------------------------

    public function testCollectionMatchesStaticRoute()
    {
        $collection = new RouteCollection;
        $route = new Route('GET', 'users', ['uses' => 'UserController@index']);
        $collection->add($route);

        $matched = $collection->match(Request::create('http://localhost/users', 'GET'));

        $this->assertSame($route, $matched);
    }

    public function testCollectionMatchesParameterRoute()
    {
        $collection = new RouteCollection;
        $route = new Route('GET', 'users/{id}', ['uses' => 'UserController@show']);
        $collection->add($route);

        $matched = $collection->match(Request::create('http://localhost/users/42', 'GET'));

        $this->assertSame($route, $matched);
    }

    public function testCollectionPrefersStaticOverParameterRoute()
    {
        $collection = new RouteCollection;
        // Register the param route first to ensure ordering is NOT registration-based.
        $paramRoute = new Route('GET', 'users/{id}', ['uses' => 'UserController@show']);
        $staticRoute = new Route('GET', 'users/profile', ['uses' => 'UserController@profile']);
        $collection->add($paramRoute);
        $collection->add($staticRoute);

        $matched = $collection->match(Request::create('http://localhost/users/profile', 'GET'));

        $this->assertSame($staticRoute, $matched);
    }

    public function testCollectionThrowsNotFoundForUnknownRoute()
    {
        $this->expectException(NotFoundHttpException::class);

        $collection = new RouteCollection;
        $collection->add(new Route('GET', 'users', ['uses' => 'UserController@index']));

        $collection->match(Request::create('http://localhost/posts', 'GET'));
    }

    public function testCollectionMatchesNestedRoute()
    {
        $collection = new RouteCollection;
        $route = new Route('GET', 'api/users/{user}/posts', ['uses' => 'PostController@index']);
        $collection->add($route);

        $matched = $collection->match(
            Request::create('http://localhost/api/users/7/posts', 'GET')
        );

        $this->assertSame($route, $matched);
    }

    public function testCollectionStillMatchesAfterMultipleRoutes()
    {
        $collection = new RouteCollection;
        $collection->add(new Route('GET', 'home', ['uses' => 'HomeController@index']));
        $collection->add(new Route('GET', 'users', ['uses' => 'UserController@index']));
        $target = new Route('GET', 'users/{id}', ['uses' => 'UserController@show']);
        $collection->add($target);
        $collection->add(new Route('POST', 'users', ['uses' => 'UserController@store']));

        $matched = $collection->match(Request::create('http://localhost/users/99', 'GET'));

        $this->assertSame($target, $matched);
    }
}
