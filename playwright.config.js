import {defineConfig} from '@playwright/test';
export default defineConfig({
 testDir:'./tests/browser', workers:1, timeout:180000, expect:{timeout:20000},
 use:{baseURL:process.env.PLAYWRIGHT_BASE_URL||'http://127.0.0.1:8765',headless:true,viewport:{width:1440,height:1050},launchOptions:{channel:'chrome'}, screenshot:'only-on-failure'},
 reporter:'list',
});
