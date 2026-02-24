<?php namespace Dilexus\Deploy\Updates;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateDilexusDeployLogs extends Migration
{
    public function down()
    {
        Schema::dropIfExists('dilexus_deploy_logs');
    }

    public function up()
    {
        Schema::create('dilexus_deploy_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id')->nullable();
            $table->foreign('server_id')
                ->references('id')
                ->on('dilexus_deploy_servers')
                ->onDelete('set null');
            $table->string('status')->default('running'); // running | success | failed
            $table->longText('output')->nullable();
            $table->string('triggered_by')->default('cli'); // cli | backend | webhook
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
        });
    }
}
