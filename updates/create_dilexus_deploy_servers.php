<?php namespace Dilexus\Deploy\Updates;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateDilexusDeployServers extends Migration
{
    public function down()
    {
        Schema::dropIfExists('dilexus_deploy_servers');
    }

    public function up()
    {
        Schema::create('dilexus_deploy_servers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('url');
            $table->string('deploy_method')->default('webhook');
            // Webhook fields
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            // SSH fields
            $table->string('ssh_host')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('ssh_user')->nullable();
            $table->string('ssh_auth_method')->default('key');
            $table->text('ssh_password')->nullable();
            $table->string('ssh_key_path')->nullable();
            $table->string('deploy_path')->nullable();
            $table->string('branch')->default('main');
            // Hooks
            $table->text('before_hooks')->nullable();
            $table->text('after_hooks')->nullable();
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
}
