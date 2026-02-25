<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

enum DdlOperationType: string
{
    case CreateTable = 'create_table';
    case DropTable = 'drop_table';
    case AddColumn = 'add_column';
    case AlterColumn = 'alter_column';
    case DropColumn = 'drop_column';
    case AddIndex = 'add_index';
    case DropIndex = 'drop_index';
    case AddForeignKey = 'add_foreign_key';
    case DropForeignKey = 'drop_foreign_key';
}
