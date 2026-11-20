(() => {
    'use strict';
    const categories={paper:'Paper & cardboard',clothes:'Clothes & textiles',metals:'Metals',appliances:'Appliances',ewaste:'E-waste',plastics:'Plastics',vehicles:'Vehicle scrap'}, $=id=>document.getElementById(id);
    let csrf='',content,uploading=false, savingProduct=false;
    const money=value=>Number(value).toLocaleString('en-IN',{maximumFractionDigits:2});
    function message(id,text='',kind=''){$(id).textContent=text;$(id).dataset.kind=kind;}
    async function api(action,data){
        const upload=data instanceof FormData;
        const response=await fetch('/api/admin.php?action='+action,{credentials:'same-origin',cache:'no-store',method:data===undefined?'GET':'POST',headers:data===undefined?{}:{'X-CSRF-Token':csrf,...(upload?{}:{'Content-Type':'application/json'})},body:data===undefined?undefined:upload?data:JSON.stringify(data)});
        const result=await response.json();
        if(!response.ok){if(response.status===401&&action!=='login')showLogin();throw new Error(result.error||'Could not complete this action.');}
        if(result.csrfToken)csrf=result.csrfToken;return result;
    }
    function showLogin(){$('workspace').hidden=$('logout').hidden=true;$('login-panel').hidden=false;$('product-editor').close();}
    function imageUrl(value){return value.startsWith('https://')?value:'/'+value.replace(/^\//,'');}
    function previewImage(){$('image-preview').src=imageUrl($('product-form').elements.img.value||'images/scrap-placeholder.svg');}
    $('image-preview').addEventListener('error',()=>{if(!$('image-preview').src.endsWith('scrap-placeholder.svg'))$('image-preview').src='/images/scrap-placeholder.svg';});
    function renderProducts(){
        const query=$('product-search').value.trim().toLowerCase(),category=$('category-filter').value;
        const products=content.products.filter(p=>(!category||p.category===category)&&(p.name+' '+categories[p.category]).toLowerCase().includes(query));
        $('product-count').textContent=`(${products.length})`;$('empty-results').hidden=products.length!==0;
        $('product-list').replaceChildren(...products.map(product=>{
            const row=document.createElement('article');row.className='admin-product';
            const img=document.createElement('img');img.src=imageUrl(product.img);img.alt='';img.loading='lazy';img.addEventListener('error',()=>{img.src='/images/scrap-placeholder.svg';},{once:true});
            const copy=document.createElement('div'),title=document.createElement('h3'),meta=document.createElement('p'),price=document.createElement('strong'),unit=document.createElement('small');
            title.textContent=product.name;meta.textContent=categories[product.category]+(product.active?'':' · Hidden');price.textContent='₹'+money(product.price);unit.textContent='/ '+product.unit;copy.append(title,meta,price,unit);
            const button=document.createElement('button');button.type='button';button.className='quiet';button.textContent='Edit';button.setAttribute('aria-label','Edit '+product.name);button.addEventListener('click',()=>editProduct(product));row.append(img,copy,button);return row;
        }));
    }
    function editProduct(product={id:'',name:'',price:'',unit:'kg',category:'paper',description:'',badge:'',img:'images/scrap-placeholder.svg',active:true}){
        const form=$('product-form');form.reset();for(const key of ['id','name','price','unit','category','description','badge','img'])form.elements.namedItem(key).value=product[key];
        form.elements.active.checked=product.active;$('editor-heading').textContent=product.id?'Edit product':'Add product';message('editor-status');previewImage();$('product-editor').showModal();form.elements.name.focus();
    }
    const settingFields=[['companyName','Company name'],['email','Contact email','email'],['phone','Phone / WhatsApp number','tel'],['supportHours','Support hours'],['address','Office address','textarea'],['ownerName','Contact person'],['ownerRole','Contact person role'],['heroTitle','Homepage banner heading'],['heroSubtitle','Homepage banner subtitle'],['footerTitle','Footer banner heading'],['footerButton','Footer button label'],['footerNote','Footer copyright text']];
    function renderSettings(){
        $('settings-fields').replaceChildren(...settingFields.map(([key,caption,type='text'])=>{
            const label=document.createElement('label');label.textContent=caption;const field=document.createElement(type==='textarea'?'textarea':'input');field.name=key;field.required=true;field.value=content.settings[key]||'';if(type!=='textarea')field.type=type;
            if(['address','heroTitle','heroSubtitle','footerTitle','footerNote'].includes(key))label.className='full';if(key==='phone')field.placeholder='+918851644415';
            field.maxLength={companyName:150,email:254,phone:20,address:500,ownerName:100,ownerRole:100,supportHours:120,heroTitle:120,heroSubtitle:200,footerTitle:200,footerButton:50,footerNote:250}[key];label.append(field);return label;
        }));
    }
    async function openWorkspace(){content=await api('content');renderProducts();renderSettings();$('login-panel').hidden=true;$('workspace').hidden=$('logout').hidden=false;$('login-form').reset();}
    async function saving(form,statusId,callback){const button=form.querySelector('[type=submit]');button.disabled=true;message(statusId,'Saving…');try{await callback();}catch(error){message(statusId,error.message,'error');}finally{button.disabled=false;}}
    for(const[id,label]of Object.entries(categories))for(const selectId of ['product-category','category-filter']){const option=document.createElement('option');option.value=id;option.textContent=label;$(selectId).append(option);}
    $('login-form').addEventListener('submit',async event=>{event.preventDefault();await saving(event.currentTarget,'login-status',async()=>{await api('login',{password:$('admin-password').value});await openWorkspace();message('login-status');});});
    $('logout').addEventListener('click',async()=>{try{await api('logout',{});showLogin();const session=await api('session');csrf=session.csrfToken;}catch(error){message('admin-status',error.message,'error');}});
    document.querySelectorAll('[data-section]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-section]').forEach(item=>item.removeAttribute('aria-current'));button.setAttribute('aria-current','page');for(const section of ['products','settings','security'])$(section+'-panel').hidden=section!==button.dataset.section;message('admin-status');}));
    $('product-search').addEventListener('input',renderProducts);$('category-filter').addEventListener('change',renderProducts);$('add-product').addEventListener('click',()=>editProduct());
    for(const id of ['close-editor','cancel-editor'])$(id).addEventListener('click',()=>{if(!uploading&&!savingProduct)$('product-editor').close();});
    $('product-editor').addEventListener('cancel',event=>{if(uploading||savingProduct)event.preventDefault();});
    $('product-form').elements.img.addEventListener('change',previewImage);
    $('image-upload').addEventListener('change',async event=>{const file=event.target.files[0];if(!file)return;if(file.size>3*1024*1024){message('editor-status','Image must be smaller than 3 MB.','error');return;}uploading=true;const save=$('product-form').querySelector('[type=submit]');save.disabled=true;message('editor-status','Uploading image…');try{const data=new FormData();data.append('image',file);const result=await api('upload',data);$('product-form').elements.img.value=result.url;previewImage();message('editor-status','Image uploaded. Save the product to publish it.','success');}catch(error){message('editor-status',error.message,'error');}finally{uploading=false;save.disabled=false;}});
    $('product-form').addEventListener('submit',async event=>{event.preventDefault();if(uploading||savingProduct)return;const form=event.currentTarget;savingProduct=true;try{await saving(form,'editor-status',async()=>{const product=Object.fromEntries(new FormData(form));product.price=Number(product.price);product.active=form.elements.active.checked;content=await api('product',{revision:content.revision,product});renderProducts();$('product-editor').close();message('admin-status','Product saved. The website now uses the updated details.','success');});}finally{savingProduct=false;}});
    $('settings-form').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget;await saving(form,'admin-status',async()=>{content=await api('settings',{revision:content.revision,settings:Object.fromEntries(new FormData(form))});message('admin-status','Contact details and banners saved.','success');});});
    $('password-form').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget;await saving(form,'admin-status',async()=>{if(form.elements.newPassword.value!==form.elements.confirmPassword.value)throw new Error('New passwords do not match.');await api('password',Object.fromEntries(new FormData(form)));form.reset();message('admin-status','Password changed. Use your new password next time.','success');});});
    $('login-form').querySelector('button').disabled=true;api('session').then(async session=>{if(session.authenticated)await openWorkspace();}).catch(error=>message('login-status',error.message,'error')).finally(()=>{$('login-form').querySelector('button').disabled=false;});
})();
