<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class AuthorizedRoutesTest extends TestCase {
    public function testEveryAuthorizedPageHasRouteMetadataWithoutChangingTheSidebar(): void {
        core('Access');
        foreach (array_keys(ROLE_LABELS) as $role) {
            $user = ['id'=>1, 'role'=>$role];
            $routes = array_column(Access::routesFor($user), null, 'id');
            foreach (Access::pages() as $id=>$page) {
                self::assertSame(Access::canOpen($user, $id), isset($routes[$id]), "$role:$id");
                if (isset($routes[$id])) self::assertFileExists(dirname(__DIR__,2).'/public/assets/js/modules/'.$routes[$id]['js'].'.js');
            }
            foreach (Access::navFor($user) as $item) self::assertSame($item, $routes[$item['id']]);
        }
        $admin = ['id'=>1, 'role'=>'admin'];
        self::assertCount(13, Access::navFor($admin));
        self::assertArrayHasKey('admin_approvals', array_column(Access::routesFor($admin), null, 'id'));
        self::assertNotContains('admin_approvals', array_column(Access::navFor($admin), 'id'));
        self::assertNotContains('admin_approvals', array_column(Access::routesFor(['id'=>2,'role'=>'operations_head']), 'id'));
    }
}
