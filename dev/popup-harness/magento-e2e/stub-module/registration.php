<?php
// LOCAL TEST ONLY (BNPL-1607). Never deploy. Replaces the Aplazo plugin's external integrations through DI.
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'LocalDev_AplazoStub',
    __DIR__
);
