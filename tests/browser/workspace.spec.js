import {test,expect} from '@playwright/test';
test('connected workspace, forms, and responsive navigation',async({page})=>{
 const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.log('BROWSER ERROR:',e.message)});
 await page.goto('/login');
 await page.getByRole('button',{name:'Use demo credentials'}).click();
 await page.getByRole('button',{name:'Sign in to workspace'}).click();
 await expect(page.getByRole('heading',{name:/Welcome back, Alex/})).toBeVisible();
 await page.screenshot({path:'storage/app/private/dashboard-desktop.png',fullPage:true});
 for(const route of ['customers','invoices','receipts','cashbook','employees','attendance','payrolls','expenses','fixed-expenses','gate-passes','reports','ledgers','settings']){
  await page.goto('/'+route);await expect(page.locator('h1')).toBeVisible();await expect(page.locator('main')).not.toContainText('Internal Server Error');
 }
 for(const route of ['customers','invoices','receipts','cashbook','employees','payrolls','expenses','fixed-expenses','gate-passes']){
  await page.goto('/'+route+'/1');await expect(page.locator('h1')).toBeVisible();
 }
 await page.goto('/customers');
 await page.getByRole('button',{name:'Add customer',exact:true}).click();
 const name='Browser Textile '+Date.now();
 await page.getByLabel('Customer / business name').fill(name);
 await page.getByLabel('Phone number',{exact:true}).fill('1555'+String(Date.now()).slice(-9));
 await page.getByRole('button',{name:'Save record',exact:true}).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await page.getByRole('link',{name,exact:true}).click();
 await page.getByRole('button',{name:'Create invoice',exact:true}).click();
 await page.getByLabel('Item 1 description').fill('Browser test cotton');
 await page.getByLabel('Item 1 quantity').fill('12');
 await page.getByLabel('Item 1 rate').fill('25');
 await expect(page.locator('.form-total')).toContainText('$300.00');
 await page.getByRole('dialog').getByRole('button',{name:'Create invoice',exact:true}).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(page.locator('.module-summary')).toContainText('$300.00');
 await page.getByRole('button',{name:'Invoices',exact:true}).click();
 await page.locator('tbody .record-link').first().click();
 await page.getByRole('button',{name:'Receive payment',exact:true}).click();
 await page.getByLabel('Received into').selectOption({index:1});
 await page.getByRole('button',{name:'Post receipt',exact:true}).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(page.locator('.card-heading .badge').first()).toHaveText('Paid');
 await expect(page.locator('.invoice-totals')).toContainText('$0.00');
 await page.getByRole('button',{name:'Create gate pass',exact:true}).click();
 await expect(page.getByLabel('Goods / material description')).toHaveValue('Browser test cotton');
 await page.getByLabel('Authorised by').fill('Browser Supervisor');
 await page.getByRole('button',{name:'Save record',exact:true}).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(page.getByRole('link',{name:/GP-/})).toBeVisible();
 await page.goto('/reports');await page.getByRole('button',{name:'HRMS',exact:true}).click();await expect(page.locator('table').first()).toContainText('Sarah Wilson');
 await page.getByRole('button',{name:'Production',exact:true}).click();await expect(page.getByText('No production records in this period')).toBeVisible();
 await page.setViewportSize({width:390,height:844});await page.goto('/');
 await expect(page.getByRole('heading',{name:/Welcome back, Alex/})).toBeVisible();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
 await page.screenshot({path:'storage/app/private/dashboard-mobile.png',fullPage:true});
 await page.getByRole('button',{name:'Open menu'}).click();await expect(page.locator('.sidebar')).toHaveClass(/is-open/);
 await page.locator('.sidebar').getByRole('link',{name:'Customers',exact:true}).click();
 await expect(page.locator('h1')).toHaveText('Customers');
 await page.getByRole('button',{name:'Add customer',exact:true}).click();await expect(page.getByRole('dialog')).toBeVisible();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
 await page.getByRole('button',{name:'Cancel',exact:true}).click();
 expect(errors).toEqual([]);
});