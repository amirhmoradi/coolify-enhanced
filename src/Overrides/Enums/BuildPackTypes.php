<?php

namespace App\Enums;

enum BuildPackTypes: string
{
    case NIXPACKS = 'nixpacks';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
    // [CORELIX ENHANCED: Additional build types]
    case RAILPACK = 'railpack';
    case HEROKU = 'heroku';
    case PAKETO = 'paketo';
    // [END CORELIX ENHANCED]
}
