<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Security\ApprovalPolicy;

final class ApprovalPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SIRO_MCP_APPROVAL_TOKEN');
    }

    public function test_mutating_tools_require_approval(): void
    {
        $policy = new ApprovalPolicy();
        $this->assertTrue($policy->requiresApproval('write_file'));
        $this->assertTrue($policy->requiresApproval('scaffold_resource'));
        $this->assertTrue($policy->requiresApproval('execute_cli', ['command' => 'migrate']));
        $this->assertFalse($policy->requiresApproval('route:list'));
    }

    public function test_token_must_match_operator_environment(): void
    {
        putenv('SIRO_MCP_APPROVAL_TOKEN=operator-secret');
        $policy = new ApprovalPolicy();

        $this->assertTrue($policy->isApproved(['approval_token' => 'operator-secret']));
        $this->assertFalse($policy->isApproved(['approval_token' => 'wrong']));
        $this->assertSame([], $policy->stripApprovalToken(['approval_token' => 'operator-secret']));
    }
}
