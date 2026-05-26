<?php

/**
 * AgentBee entry script
 *
 * Copyright 2026 秋水之冰 <27206617@qq.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types = 1);

const AGENT_VERSION = '2026.5.26';

use Nervsys\Core\Lib\App;

require __DIR__ . '/../../Nervsys/NS.php';

$root = dirname(__DIR__, 2);

$ns = new Nervsys\NS($root, 'modules', App::MODE_MODULE);

$ns->setDebugMode(false);
$ns->go();
