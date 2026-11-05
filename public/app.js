const transport=new BalootHttpClient(),$=id=>document.getElementById(id);
let roomCode=null,mySeat=null,latest=null,account=null,csrfToken=null,selectedCardId=null,queuedCardId=null,playRequestInFlight=false,qaidState=null,qaidTimer=null,lastSeenPlayCount=null,dismissedResultRound=null;
let lastRenderedHandIds=[],dealAnimationToken=0,timeoutOverlay=$('turnTimeout'),lastPurchaseAnimationKey='',countdownTicker=null;
let auctionCallUntil=[0,0,0,0],auctionCallPulse=[0,0,0,0],auctionCallText=['','','',''],auctionCallTimers=[null,null,null,null];
function trackAuctionCalls(s){
 if(s.phase==='playing'||['roundEnd','gameEnd','lobby'].includes(s.phase)){
  auctionCallUntil=[0,0,0,0];auctionCallText=['','','',''];auctionCallTimers.forEach((timer)=>clearTimeout(timer));auctionCallTimers=[null,null,null,null];return;
 }
 if(latest&&['auction1','auction2'].includes(latest.phase)&&(s.turn!==latest.turn||s.phase!==latest.phase)){
  const actor=Number(latest.turn);let label=s.auctionCalls?.[actor]||'';
  if(!label&&latest.phase==='auction2'&&s.phase==='auction1')label='ولا';
  if(Number.isInteger(actor)&&actor>=0&&actor<4&&label){
   auctionCallText[actor]=label;auctionCallUntil[actor]=Date.now()+5000;auctionCallPulse[actor]++;
   clearTimeout(auctionCallTimers[actor]);
   auctionCallTimers[actor]=setTimeout(()=>{if(Date.now()>=auctionCallUntil[actor]){auctionCallUntil[actor]=0;const rel=(actor-mySeat+4)%4;document.querySelector('#seat'+rel+' .seat-call')?.remove();}},5100);
  }
 }
}
let guestId=localStorage.getItem('baloot400Guest')||crypto.randomUUID();localStorage.setItem('baloot400Guest',guestId);
const ROOM_TTL=60*60*1000;
function saveRoomCode(code){localStorage.setItem('baloot400Room',code);localStorage.setItem('baloot400RoomAt',String(Date.now()));}
function getRecentRoom(){const code=localStorage.getItem('baloot400Room'),at=Number(localStorage.getItem('baloot400RoomAt')||0);if(!code||!at||Date.now()-at>ROOM_TTL){localStorage.removeItem('baloot400Room');localStorage.removeItem('baloot400RoomAt');return '';}return code;}
const savedName=localStorage.getItem('baloot400PlayerName');if(savedName)$('playerName').value=savedName;
const name=()=> $('playerName').value.trim()||'ضيف';
$('playerName').addEventListener('input',()=>localStorage.setItem('baloot400PlayerName',$('playerName').value));
const status=m=>{$('status').textContent=m;};
const emit=(event,data)=>transport.emit(event,data,r=>{if(!r?.ok)status(r?.error||'تعذر الطلب');else status('تم');});
function sendButtonAction(button,event,data={}){if(!button||button.disabled)return;button.disabled=true;button.classList.add('is-pending');button.setAttribute('aria-busy','true');status('جارٍ إرسال الأمر...');transport.emit(event,{code:roomCode,...data},result=>{if(!result?.ok){button.disabled=false;button.classList.remove('is-pending');button.removeAttribute('aria-busy');status(result?.error||'تعذر تنفيذ الأمر');}else status('تم استلام الأمر.');});}
async function authRequest(url,body={}){
 const headers={'Content-Type':'application/json'};const action=new URL(url,location.href).searchParams.get('action');
 if(['profile','logout','logout_all'].includes(action)&&csrfToken)headers['X-CSRF-Token']=csrfToken;
 const r=await fetch(url,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify(body)});
 const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||data.error||'تعذر تنفيذ الطلب');return data;
}
async function withButton(button,task){if(!button||button.disabled)return;button.disabled=true;button.setAttribute('aria-busy','true');try{return await task();}finally{button.disabled=false;button.removeAttribute('aria-busy');}}
function showAccount(user){
 account=user||null;
 if(account?.guestId){guestId=account.guestId;localStorage.setItem('baloot400Guest',guestId);}
 if(account?.displayName){$('playerName').value=account.displayName;localStorage.setItem('baloot400PlayerName',account.displayName);}
 $('accountStatus').textContent=account?'مرحباً '+account.displayName+' · '+account.email:'تلعب كضيف. أنشئ حساباً لحفظ هويتك ونقاطك ونتائجك.';
 $('logoutAccount').classList.toggle('hidden',!account);$('registerAccount').classList.toggle('hidden',!!account);$('loginAccount').classList.toggle('hidden',!!account);$('forgotAccount').classList.toggle('hidden',!!account);
 $('profilePanel').classList.toggle('hidden',!account);
 $('menuGuestActions')?.classList.toggle('hidden',!!account);$('menuUserActions')?.classList.toggle('hidden',!account);document.body.classList.toggle('authenticated',!!account);
 if(!account)return;
 $('profileDisplayName').value=account.displayName||'';$('profileAvatar').value=account.avatar||'🃏';$('profileBio').value=account.bio||'';
 const stats=account.stats||{};$('profilePoints').textContent=Number(stats.points||0).toLocaleString('ar-SA');$('profileMatches').textContent=Number(stats.matches||0).toLocaleString('ar-SA');$('profileWins').textContent=Number(stats.wins||0).toLocaleString('ar-SA');$('profileLosses').textContent=Number(stats.losses||0).toLocaleString('ar-SA');
}
async function applyAuth(data){
 csrfToken=data.csrf||null;showAccount(data.user);
 if(roomCode)transport.emit('room:resume',{code:roomCode,guestId},r=>{if(r.ok){mySeat=r.seat;status('تم استعادة مقعدك من حسابك.');}});
}
$('registerAccount').onclick=()=>withButton($('registerAccount'),async()=>{try{const d=await authRequest('/api/auth.php?action=register',{email:$('accountEmail').value.trim(),password:$('accountPassword').value,displayName:$('accountName').value||name()});$('accountStatus').textContent=d.message;}catch(e){$('accountStatus').textContent=e.message;}});
$('loginAccount').onclick=()=>withButton($('loginAccount'),async()=>{try{const d=await authRequest('/api/auth.php?action=login',{email:$('accountEmail').value.trim(),password:$('accountPassword').value,remember:false});await applyAuth(d);}catch(e){$('accountStatus').textContent=e.message;}});
$('forgotAccount').onclick=()=>withButton($('forgotAccount'),async()=>{try{const email=$('accountEmail').value.trim();if(!email)throw new Error('أدخل بريدك الإلكتروني أولاً.');const d=await authRequest('/api/auth.php?action=forgot',{email});$('accountStatus').textContent=d.message;}catch(e){$('accountStatus').textContent=e.message;}});
$('resetPasswordButton').onclick=()=>withButton($('resetPasswordButton'),async()=>{try{const token=new URLSearchParams(location.search).get('reset')||'';const d=await authRequest('/api/auth.php?action=reset',{token,password:$('resetPassword').value});history.replaceState({},'',location.pathname);$('resetPanel').classList.add('hidden');$('accountStatus').textContent=d.message+' يمكنك الآن تسجيل الدخول.';}catch(e){$('accountStatus').textContent=e.message;}});
$('saveProfile').onclick=()=>withButton($('saveProfile'),async()=>{try{const d=await authRequest('/api/auth.php?action=profile',{displayName:$('profileDisplayName').value,avatar:$('profileAvatar').value,bio:$('profileBio').value});showAccount(d.user);$('profileMessage').textContent='تم حفظ الملف الشخصي.';}catch(e){$('profileMessage').textContent=e.message;}});
async function logoutAccount(){try{await authRequest('/api/auth.php?action=logout',{});csrfToken=null;showAccount(null);if($('accountStatus'))$('accountStatus').textContent='تم تسجيل الخروج.';}catch(e){if($('accountStatus'))$('accountStatus').textContent=e.message;}}
$('logoutAccount').onclick=()=>withButton($('logoutAccount'),logoutAccount);

async function restoreAccount(){
 if(new URLSearchParams(location.search).has('reset')){$('resetPanel')?.classList.remove('hidden');$('account')?.scrollIntoView({behavior:'smooth',block:'center'});}
 try{const d=await authRequest('/api/auth.php?action=me',{});csrfToken=d.csrf||null;showAccount(d.user);if(roomCode)transport.emit('room:resume',{code:roomCode,guestId},r=>{if(r.ok){mySeat=r.seat;status('تم استعادة مقعدك من حسابك.');}});}
 catch{showAccount(null);}
}
$('createRoom').onclick=()=>withButton($('createRoom'),()=>new Promise(resolve=>transport.emit('room:create',{name:name(),guestId,qaidEnabled:!!$('qaidEnabled')?.checked},r=>{if(!r.ok){status(r.error);resolve();return;}roomCode=r.code;saveRoomCode(roomCode);location.href='/table.html?room='+encodeURIComponent(roomCode);resolve();})));
$('quickJoin')?.addEventListener('click',()=>{const button=$('quickJoin');button.disabled=true;button.textContent='جارٍ البحث عن غرفة...';transport.emit('room:quick-join',{name:name(),guestId,qaidEnabled:!!$('qaidEnabled')?.checked},r=>{button.disabled=false;button.textContent='دخول غرفة / العب الآن';if(!r?.ok)return status(r?.error||'تعذر العثور على غرفة');roomCode=r.code;mySeat=r.seat;saveRoomCode(roomCode);status(r.created?'لم توجد غرفة مناسبة؛ أنشأنا لك غرفة جديدة.':r.qaidEnabled?'تم إدخالك إلى غرفة مفعّل فيها القيد.':'دخلت غرفة انتظار، لكن قاعدة القيد غير مفعّلة فيها.');location.href='/table.html?room='+encodeURIComponent(roomCode);});});
$('joinRoom').onclick=()=>withButton($('joinRoom'),()=>new Promise(resolve=>transport.emit('room:join',{code:$('roomCode').value.trim().toUpperCase(),name:name(),guestId},r=>{if(!r.ok){status(r.error);resolve();return;}roomCode=r.code;mySeat=r.seat;saveRoomCode(roomCode);location.href='/table.html?room='+encodeURIComponent(roomCode);resolve();})));
$('startGame')?.addEventListener('click',()=>sendButtonAction($('startGame'),'room:start'));
function registerGameApp(){if('serviceWorker'in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));}
registerGameApp();
$('shareRoom')?.addEventListener('click',async()=>{if(!roomCode)return;const url=location.origin+'/?room='+encodeURIComponent(roomCode);try{if(navigator.share)await navigator.share({title:'بلوت 400',text:'انضم إلى غرفة البلوت',url});else if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(url);status('تم نسخ رابط الغرفة.');}else{const field=document.createElement('textarea');field.value=url;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove();status('تم نسخ رابط الغرفة.');}}catch(e){if(e.name!=='AbortError')status('تعذرت مشاركة الرابط.');}});
const roomFromUrl=new URLSearchParams(location.search).get('room');if(roomFromUrl&&$('roomCode'))$('roomCode').value=roomFromUrl.toUpperCase();
transport.on('connect',()=>{if($('status'))status('متصل بالخادم.');const target=roomFromUrl?.toUpperCase()||getRecentRoom();if(target){roomCode=target;if($('roomCode'))$('roomCode').value=target;transport.emit('room:resume',{code:target,guestId},r=>{if(r.ok){mySeat=r.seat;if($('status'))status('تم استعادة مقعدك.');}else if($('status'))status(r.error||'تعذر فتح الغرفة.');});}});
transport.on('disconnect',()=>status('انقطع الاتصال؛ نحاول إعادة الاتصال.'));
function playCardNow(id){if(playRequestInFlight)return;playRequestInFlight=true;selectedCardId=null;queuedCardId=null;const button=document.querySelector('[data-card="'+CSS.escape(id)+'"]');if(button){button.disabled=true;button.classList.add('is-playing','is-pending');button.setAttribute('aria-busy','true');}status('جارٍ إرسال الورقة...');transport.emit('room:play',{code:roomCode,cardId:id},result=>{playRequestInFlight=false;if(!result?.ok){if(button){button.disabled=false;button.classList.remove('is-playing','is-pending');button.removeAttribute('aria-busy');}status(result?.error||'تعذر لعب الورقة');if(latest)renderControls(latest);}else status('تم استلام الورقة.');});}

function animatePurchasedUpcard(s,previous){
 if(!previous||!['postPurchase','buyerConfirm','buyerSelectHukm'].includes(previous.phase)||!['projectDeclaration','playing'].includes(s.phase)||!s.contract||!Number.isInteger(s.contract.buyer))return;
 const cardId=s.upCard?.id||previous.upCard?.id||'card',key=[s.code,s.contract.buyer,cardId].join(':');
 if(lastPurchaseAnimationKey===key)return;lastPurchaseAnimationKey=key;
 const receiver=Number.isInteger(s.contract.cardReceiver)?s.contract.cardReceiver:s.contract.buyer;const relative=(receiver-s.mySeat+4)%4,target=$('seat'+relative)?.querySelector('.seat-avatar'),board=$('board');
 const sourceCard=$('upcard')?.querySelector('.upcard-visible')||$('trick')?.querySelector('.played-card');
 if(!target||!board)return;
 const sourceRect=(sourceCard||$('trick')||board).getBoundingClientRect(),targetRect=target.getBoundingClientRect();
 const width=sourceCard?sourceRect.width:42,height=sourceCard?sourceRect.height:59;
 const left=sourceRect.left+sourceRect.width/2-width/2,top=sourceRect.top+sourceRect.height/2-height/2;
 const dx=targetRect.left+targetRect.width/2-(left+width/2),dy=targetRect.top+targetRect.height/2-(top+height/2);
 const flight=document.createElement('div');flight.className='purchase-flight-card';
 flight.style.left=left+'px';flight.style.top=top+'px';flight.style.width=width+'px';flight.style.height=height+'px';
 flight.innerHTML=sourceCard?sourceCard.innerHTML:cardFace(s.upCard||previous.upCard);document.body.appendChild(flight);
 const animation=flight.animate([{transform:'translate(0,0) scale(1)',opacity:1},{transform:'translate('+dx+'px,'+dy+'px) scale(.58)',opacity:.92}],{duration:680,easing:'cubic-bezier(.22,.75,.25,1)'});
 animation.onfinish=()=>flight.remove();animation.oncancel=()=>flight.remove();
}
function updateAvatarCountdowns(){
 document.querySelectorAll('.avatar-countdown').forEach(node=>{
  const deadline=Number(node.dataset.deadline)*1000,duration=Math.max(1,Number(node.dataset.duration)||10),remaining=deadline-Date.now();
  const seconds=Math.max(0,Math.ceil(remaining/1000)),progress=Math.max(0,Math.min(1,remaining/(duration*1000)));
  node.style.setProperty('--progress',(progress*100)+'%');
  const number=node.querySelector('b');if(number)number.textContent=String(seconds);
  node.classList.toggle('is-expired',seconds===0);
 });
 if(latest?.timeoutPrompt&&latest.timeoutDeadlineAt){
  const remaining=Number(latest.timeoutDeadlineAt)*1000-Date.now(),seconds=Math.max(0,Math.ceil(remaining/1000));
  const number=$('timeoutCountdownText'),message=$('timeoutMessage');
  if(number)number.textContent=String(seconds);
  if(message)message.textContent=seconds>0?'سيستلم البوت المقعد بعد '+seconds+' ثوانٍ. يمكنك العودة الآن.':'جارٍ تسليم المقعد للبوت...';
 }
}

function clearQaidTimer(){if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;}
function startQaidTimer(){clearQaidTimer();qaidTimer=setInterval(()=>{if(!qaidState)return;const left=Math.max(0,Math.ceil((qaidState.deadline-Date.now())/1000));const clock=document.querySelector('#qaidPage .qaid-countdown');if(clock)clock.textContent=left>0?'المتبقي: '+left+' ثانية':'انتهت المهلة — جارٍ احتساب النتيجة للمنافس';if(left<=0)document.querySelectorAll('#qaidPage button').forEach(button=>button.disabled=true);},250);}
function closeQaidView(){qaidState=null;clearQaidTimer();document.body.classList.remove('qaid-review-open');document.documentElement.classList.remove('qaid-review-open');$('qaidPage').classList.add('hidden');$('board').classList.remove('hidden');$('controls').classList.remove('hidden');if(latest&&['roundEnd','gameEnd'].includes(latest.phase)&&latest.resultVisible){$('resultPage').classList.remove('hidden');}else $('resultPage').classList.add('hidden');}
function submitQaid(){const state=qaidState;if(!state||state.submitting)return;if(Date.now()>=state.deadline){status('انتهت مهلة القيد؛ يجري احتساب النتيجة للمنافس.');return;}state.submitting=true;document.querySelectorAll('#qaidPage button').forEach(button=>button.disabled=true);transport.emit('room:claim',{code:roomCode},result=>{if(!result?.ok){if(qaidState){qaidState.submitting=false;document.querySelectorAll('#qaidPage button').forEach(button=>button.disabled=false);}status(result?.error||'تعذر احتساب القيد');}else{closeQaidView();status('تم احتساب القيد؛ عادت الطاولة.');}});}
transport.on('room:update',s=>{if(!$('table'))return;trackAuctionCalls(s);if(s.qaidWindowActive&&s.qaidDeadline&&!s.qaidResult){const deadline=Number(s.qaidDeadline)*1000;if(Number.isFinite(deadline)){if(!qaidState||qaidState.deadline!==deadline)qaidState={deadline,submitting:false};if(!qaidTimer)startQaidTimer();}}else if(qaidState){closeQaidView();}if(s.phase!=='roundEnd')dismissedResultRound=null;if(s.qaidWindowActive&&!latest?.qaidWindowActive)dismissedResultRound=null;if(s.qaidResult&&!latest?.qaidResult)dismissedResultRound=null;const previousSnapshot=latest;latest=s;roomCode=s.code;mySeat=s.mySeat;$('table').classList.remove('hidden');$('code').textContent=s.code;$('shareRoom')?.setAttribute('data-room',s.code);
 s.players.forEach((p)=>{const rel=(p.seat-s.mySeat+4)%4,el=$('seat'+rel);if(!el)return;el.className='seat seat-'+['bottom','left','top','right'][rel]+(p.seat===s.turn?' active-turn':'');const backs=rel===0?'':Array.from({length:Math.min(8,s.handCounts?.[p.seat]??0)},(_,i)=>'<i class="back-card" style="transform:rotate('+((i-3.5)*7)+'deg) translateY('+ (Math.abs(i-3.5)*3.5) +'px)"></i>').join('');const isAuction=['auction1','auction2','projectDeclaration'].includes(s.phase);const dealerTag=p.seat===s.dealer&&isAuction?'<div class="seat-role">موزع</div>':'';const callTag=auctionCallUntil[p.seat]>Date.now()&&s.phase!=='playing'&&!['roundEnd','gameEnd'].includes(s.phase)?'<span class="seat-call pulse-'+(auctionCallPulse[p.seat]%2?'a':'b')+'">'+escapeHtml(auctionCallText[p.seat]||'')+'</span>':'';const contractTag=(p.seat===s.buyer&&s.contract&&['projectDeclaration','playing','postPurchase','buyerConfirm','buyerSelectHukm'].includes(s.phase)?'<span class="seat-contract">'+(s.contract.type==='sun'?'صن':(s.contract.trump?('حكم <b class="'+(s.contract.trump==='D'?'contract-diamond':'')+'">'+symbol(s.contract.trump)+'</b>'):(s.contract.purchaseRound===2?'حكم ثانٍ — بانتظار اختيار النوع':'حكم')))+'</span>':'')+(s.contract?.purchaseType==='ashkal'&&s.contract?.ashkalCallerSeat===p.seat?'<span class="seat-contract">أشكل</span>':'')+((s.contract?.doubleCallerSeat===p.seat||s.contract?.lastCallerSeat===p.seat)?'<span class="seat-contract">دبل</span>':'');const seatProjects=(s.projectsBySeat?.[p.seat]||[]).map(x=>x.label||({'20':'سرا','50':'خمسين','100-run':'مائة متسلسلة','100-set':'مائة ورق','400':'أربعمائة'}[x.type]||x.type)).join(' · ');const projectTag=seatProjects&&s.phase==='playing'&&!s.tricksPlayed?'<span class="seat-project">'+escapeHtml(seatProjects)+'</span>':'';const turnTag='';const botStyleTag=p.bot&&p.botStyleLabel?'<span class="bot-style-tag">'+escapeHtml(p.botStyleLabel)+'</span>':'';const displayCards=(s.projectDisplayBySeat?.[p.seat]||[]).flatMap(x=>x.cards||[]);const projectCards=displayCards.length?'<div class="project-cards" aria-label="أوراق المشروع">'+displayCards.map(c=>'<span class="project-card '+('HD'.includes(c.suit)?'red':'')+'">'+cardFace(c)+'</span>').join('')+'</div>':'';const playerLabel=p.bot?'كمبيوتر '+(p.botNumber||p.seat+10):p.name;const avatar=p.avatar||(p.bot?'🤖':'👤');const canOpenProfile=!p.empty&&!(s.phase==='lobby'&&p.bot);const timerSeat=Number.isInteger(s.timeoutSeat)?s.timeoutSeat:Number(s.turn);const timerDeadline=Number(s.timeoutSeat===p.seat?s.timeoutDeadlineAt:s.turnDeadlineAt);const timerDuration=s.timeoutSeat===p.seat?5:Number(s.turnDeadlineDuration||10);const avatarTimer=p.seat===timerSeat&&timerDeadline>0?'<span class="avatar-countdown" data-deadline="'+timerDeadline+'" data-duration="'+timerDuration+'"><b>'+timerDuration+'</b></span>':'';const level=playerLevel(p.profile);const levelTag=level?'<span class="seat-level">'+escapeHtml(level)+'</span>':'';const profileTrigger=canOpenProfile?'<button type="button" class="seat-avatar seat-profile-trigger" aria-label="ملف '+escapeHtml(playerLabel)+'">'+escapeHtml(avatar)+avatarTimer+'</button>':'<div class="seat-avatar" aria-hidden="true">'+escapeHtml(avatar)+avatarTimer+'</div>';const nameTrigger=canOpenProfile?'<button type="button" class="seat-name seat-profile-trigger" title="عرض ملف اللاعب">'+escapeHtml(playerLabel)+'</button>':'<div class="seat-name">'+escapeHtml(playerLabel)+'</div>';el.innerHTML='<div class="opponent-hand">'+backs+'</div><div class="seat-identity">'+profileTrigger+nameTrigger+'<div class="seat-badges">'+turnTag+dealerTag+callTag+contractTag+projectTag+levelTag+'</div></div>'+projectCards;if(canOpenProfile)el.querySelectorAll('.seat-profile-trigger').forEach(trigger=>trigger.addEventListener('click',event=>{event.stopPropagation();openPlayerProfile(p);}));
 if(s.phase==='lobby'&&p.seat!==s.mySeat&&(p.empty||p.bot)){el.classList.add('seat-selectable');el.setAttribute('role','button');el.setAttribute('tabindex','0');el.setAttribute('aria-label','الانتقال إلى مقعد '+(p.bot?'الكمبيوتر':'بانتظار لاعب'));el.onclick=()=>{const targetLabel=p.bot?'مكان الكمبيوتر':'مكان بانتظار لاعب';if(confirm('الانتقال إلى '+targetLabel+'؟'))transport.emit('room:move-seat',{code:roomCode,targetSeat:p.seat},r=>{if(!r?.ok)status(r?.error||'تعذر تغيير المقعد');else status('تم تغيير مقعدك.');});};}else{el.classList.remove('seat-selectable');el.onclick=null;el.removeAttribute('role');el.removeAttribute('tabindex');}
 });
 if(timeoutOverlay)$('seat0')?.appendChild(timeoutOverlay);
 $('startGame').hidden=!(s.phase==='lobby'&&s.players[s.mySeat]?.isOwner);
 
 const playCount=(s.tricksPlayed||0)*4+(s.trick?.length||0);
 const newPlay=lastSeenPlayCount!==null&&playCount>lastSeenPlayCount;
 lastSeenPlayCount=playCount;
 $('phase').innerHTML=phaseDisplay(s.phase,s.contract);$('score').innerHTML='<div class="score-panel"><div class="score-points"><small>الأبناط</small><b>'+(s.mySeat%2===0?(s.runningRaw?.[0]||0):(s.runningRaw?.[1]||0))+' - '+(s.mySeat%2===0?(s.runningRaw?.[1]||0):(s.runningRaw?.[0]||0))+'</b></div><div class="score-teams"><span class="score-team">لنا <b>'+(s.mySeat%2===0?s.scores[0]:s.scores[1])+'</b></span><span class="score-team">لهم <b>'+(s.mySeat%2===0?s.scores[1]:s.scores[0])+'</b></span></div></div><div id="qaidTopControl"></div>';
 animatePurchasedUpcard(s,previousSnapshot);
 $('upcard').innerHTML=['postPurchase','buyerConfirm','buyerSelectHukm'].includes(s.phase)&&s.upCard?'<span class="upcard-visible '+('HD'.includes(s.upCard.suit)?'red':'')+'">'+cardFace(s.upCard)+'</span>':'';
 const sc=s.lastScoring;
 const visibleTrick=s.trick.length?s.trick:(s.lastTrick||[]);const announcement=['roundEnd','gameEnd'].includes(s.phase)&&!s.trickClearing?(s.lastScoring?.buyerSucceeded?'مشتري ناجح':(s.contract?.level>=2?'دبل ناجح':'المشتري خسر')):'';const totalWon=(s.tricksWonBySeat||[]).reduce((sum,n)=>sum+(n||0),0);const pileCount=Math.min(52,totalWon*4);const wonBackdrop=pileCount?'<div class="won-backdrop" aria-hidden="true">'+Array.from({length:pileCount},(_,i)=>'<i class="back-card" style="transform:translate('+((i-(pileCount-1)/2)*1.1)+'px,'+((i%5)*.45)+'px) rotate('+((i-(pileCount-1)/2)*.28)+'deg)"></i>').join('')+'</div>':'';$('trick').innerHTML=wonBackdrop+(announcement&&s.resultVisible?'<div class="center-announcement">'+announcement+'</div>':visibleTrick.length?visibleTrick.map((x,i)=>'<span class="played-card '+(s.trickClearing?'clearing ':'')+(newPlay&&i===visibleTrick.length-1?'card-arriving ':'')+'at-'+((x.seat-s.mySeat+4)%4)+' '+('HD'.includes(x.card.suit)?'red':'')+'">'+cardFace(x.card)+'</span>').join(''):(['auction1','auction2'].includes(s.phase)&&s.upCard?'<span class="played-card '+('HD'.includes(s.upCard.suit)?'red':'')+'">'+cardFace(s.upCard)+'</span>':''));
 const resultPage=$('resultPage'),qaidPage=$('qaidPage');const showResult=['roundEnd','gameEnd'].includes(s.phase)&&s.resultVisible;const showQaid=!!qaidState&&!!s.qaidWindowActive&&!s.qaidResult;resultPage.innerHTML=showResult?roundSummary(s):'';resultPage.classList.toggle('hidden',!showResult||showQaid);qaidPage.classList.toggle('hidden',!showQaid);$('board').classList.toggle('hidden',showResult||showQaid);$('controls').classList.toggle('hidden',showResult||showQaid);$('qaidHeaderButton').hidden=!s.qaidEnabled||!['playing','roundEnd','gameEnd'].includes(s.phase);$('qaidHeaderButton').disabled=!!s.qaidWindowActive;$('qaidHeaderButton').title='طلب القيد متاح';

 const openQaid=()=>{if(!s.qaidEnabled||!['playing','roundEnd','gameEnd'].includes(s.phase)||(s.phase==='roundEnd'&&!s.resultVisible)||s.qaidWindowActive)return status('طلب القيد غير متاح الآن.');const trigger=document.activeElement;if(trigger?.tagName==='BUTTON'){trigger.disabled=true;trigger.classList.add('is-pending');trigger.setAttribute('aria-busy','true');}transport.emit('room:qaid-open',{code:roomCode},r=>{if(trigger?.tagName==='BUTTON'){trigger.disabled=false;trigger.classList.remove('is-pending');trigger.removeAttribute('aria-busy');}if(!r?.ok)return status(r?.error||'تعذر بدء مهلة القيد');const deadline=Number(r.expiresAt);if(!Number.isFinite(deadline)||deadline<=Date.now()){status('وصل وقت انتهاء القيد بصورة غير صحيحة؛ حدّث الصفحة وحاول مجدداً.');return;}qaidState={deadline,submitting:false};startQaidTimer();if(latest)renderControls(latest);});};const qaidSummaryButton=$('resultPage').querySelector('[data-qaid-open]');if(qaidSummaryButton)qaidSummaryButton.onclick=openQaid;$('qaidHeaderButton').onclick=openQaid;
 const replayButton=$('resultPage').querySelector('[data-replay]');if(replayButton)replayButton.onclick=e=>{e.stopPropagation();sendButtonAction(replayButton,'room:replay');};
 const continueButton=$('resultPage').querySelector('[data-continue]');if(continueButton)continueButton.onclick=()=>sendButtonAction(continueButton,'room:result-continue');
 $('pointsLive').textContent='';
 const handCards=s.hand||[];const previousHandIds=new Set(lastRenderedHandIds);const handChanged=handCards.length!==lastRenderedHandIds.length||handCards.some(c=>!previousHandIds.has(c.id));const newHandIds=new Set(handChanged?handCards.filter(c=>!previousHandIds.has(c.id)).map(c=>c.id):[]);if(handChanged){dealAnimationToken++;lastRenderedHandIds=handCards.map(c=>c.id);}const dealToken=dealAnimationToken;$('hand').innerHTML=handCards.map((c,i,arr)=>'<button class="playing-card '+('HD'.includes(c.suit)?'red':'')+(selectedCardId===c.id?' selected':'')+(newHandIds.has(c.id)?' card-dealing':'')+'" style="--fan-y:'+Math.round(Math.abs(i-(arr.length-1)/2)*3.5)+'px;--fan-r:'+((i-(arr.length-1)/2)*5)+'deg;--deal-delay:'+(newHandIds.has(c.id)?[...newHandIds].indexOf(c.id)*150:0)+'ms" data-card="'+c.id+'">'+cardFace(c)+'</button>').join('');
 document.querySelectorAll('.full-card-art').forEach(img=>{img.onerror=()=>{img.hidden=true;const fallback=img.nextElementSibling;if(fallback)fallback.hidden=false;};});
 $('myProjects').innerHTML='';
 document.querySelectorAll('[data-card]').forEach(b=>{b.disabled=!!s.players[s.mySeat]?.botControl;b.onclick=()=>{if(s.phase!=='playing'||s.players[s.mySeat]?.botControl)return;const id=b.dataset.card;const mobile=window.matchMedia('(max-width: 560px)').matches;if(s.turn!==s.mySeat){if(selectedCardId!==id){selectedCardId=id;queuedCardId=null;document.querySelectorAll('[data-card]').forEach(x=>x.classList.toggle('selected',x.dataset.card===id));}else{queuedCardId=id;status('تم اعتماد الورقة المحددة؛ ستنزل تلقائياً عند وصول دورك إذا كانت نظامية.');}return;}if(mobile&&selectedCardId!==id){selectedCardId=id;document.querySelectorAll('[data-card]').forEach(x=>x.classList.toggle('selected',x.dataset.card===id));return;}playCardNow(id);};});
 if(queuedCardId&&s.phase==='playing'&&s.turn===s.mySeat&&handCards.some(card=>card.id===queuedCardId)){const queued=queuedCardId;if((s.legalCardIds||[]).includes(queued)){playCardNow(queued);}else{queuedCardId=null;status('الورقة المحددة مخالفة؛ اختر ورقة مسموحاً بها حسب قواعد اللعب.');}}
 const timeout=$('turnTimeout');if(timeout)timeout.classList.toggle('hidden',!s.timeoutPrompt);renderControls(s);
});
function renderControls(s){const el=$('controls');el.innerHTML='';const top=$('qaidTopControl');if(top)top.innerHTML='';if(qaidState&&s.qaidEnabled&&['playing','roundEnd','gameEnd'].includes(s.phase)&&!s.qaidResult){renderQaidChooser(s,$('qaidPage'));$('board').classList.add('hidden');$('controls').classList.add('hidden');return;}else if(s.qaidResult&&qaidState){qaidState=null;if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;$('qaidPage').classList.add('hidden');$('resultPage').classList.remove('hidden');}
 if(s.players[s.mySeat]?.botControl){const back=document.createElement('button');back.textContent='العودة للعب';back.className='secondary bot-return';back.onclick=()=>sendButtonAction(back,'room:timeout-return');el.appendChild(back);return;}
 if(s.phase==='lobby')return;
 if(s.phase==='projectDeclaration'&&!s.projectDeclared?.[s.mySeat]){
  const labels={'20':'سرا','50':'خمسين','100-run':'مائة متسلسلة','100-set':'مائة ورق','400':'أربعمائة'};
  (s.projectOptions||[]).forEach(p=>{const b=document.createElement('button');b.textContent='أعلن '+(labels[p.type]||p.label);b.onclick=()=>sendButtonAction(b,'room:project',{projectType:p.type});el.appendChild(b);});
  const pass=document.createElement('button');pass.textContent='بدون مشروع';pass.className='secondary';pass.onclick=()=>sendButtonAction(pass,'room:project',{projectType:'pass'});el.appendChild(pass);
  return;
 }
 if(s.phase==='projectDeclaration'){return;}
 if(s.phase==='postPurchase'||s.phase==='buyerConfirm'||s.phase==='buyerSelectHukm'){
  const labels={pass:'بس',sun:'صن',double:'دبل',ashkal:'أشكل',confirmHukm:'تأكيد الحكم',takeBefore:'قبلك',S:'حكم ♠',H:'حكم ♥',D:'حكم ♦',C:'حكم ♣'};
  const options=s.postPurchaseOptions||[];
  if(options.length){options.forEach(choice=>{const b=document.createElement('button');b.textContent=labels[choice]||choice;b.className=['pass','confirmHukm'].includes(choice)?'secondary':'';b.onclick=()=>sendButtonAction(b,'room:post-purchase',{choice});el.appendChild(b);});}
  
  return;
 }
 if(['auction1','auction2'].includes(s.phase)&&s.turn===s.mySeat){
  const add=(v,t,secondary=false)=>{const b=document.createElement('button');b.textContent=t;b.className=secondary?'secondary':'';b.onclick=()=>sendButtonAction(b,'room:bid',{choice:v});el.appendChild(b);};
  add('pass',s.phase==='auction2'?'ولا':'بس',true);
  if(s.phase==='auction2'){
   add('takeSecond','حكم ثاني');
   add('sun','صن');
   return;
  }
  const hukm=document.createElement('button');hukm.textContent='حكم';hukm.onclick=()=>sendButtonAction(hukm,'room:bid',{choice:'take'});el.appendChild(hukm);add('sun','صن');
 }
 if(s.phase==='playing'&&s.doubleOptions?.length){
  s.doubleOptions.forEach(call=>{const labels={double:'دبل',three:'ثري',four:'فور',coffee:'قهوة'};const b=document.createElement('button');b.textContent=labels[call]||call;b.className='secondary';b.onclick=()=>sendButtonAction(b,'room:double',{call});el.appendChild(b);});
 }
 if(s.phase==='roundEnd')return;
 if(s.phase==='gameEnd')return;
}
function renderQaidChooser(s,container){
 const scrollTop=container.querySelector('.qaid-history-board')?.scrollTop||0;
 const history=s.qaidHistory||[],state=qaidState;if(!state)return;
 const left=Math.max(0,Math.ceil((state.deadline-Date.now())/1000));container.innerHTML='';
 const panel=document.createElement('div');panel.className='qaid-panel qaid-visual-panel';
 const head=document.createElement('div');head.className='qaid-board-head';
 const title=document.createElement('strong');title.textContent='كشف أوراق القيد';head.appendChild(title);
 const clock=document.createElement('span');clock.className='qaid-countdown';clock.textContent=left>0?'المتبقي: '+left+' ثانية':'انتهت المهلة — جارٍ احتساب النتيجة للمنافس';head.appendChild(clock);
 const close=document.createElement('button');close.type='button';close.className='qaid-close';close.textContent='إغلاق';close.disabled=left<=0||!!state.submitting;close.onclick=submitQaid;head.appendChild(close);panel.appendChild(head);
 const instruction=document.createElement('p');instruction.className='qaid-instruction';instruction.textContent='تظهر الورقة المخالفة باللون الأحمر وورقة الإثبات باللون الأخضر. لا تحتاج إلى اختيار أي ورقة؛ أغلق الكشف أو اضغط تأكيد القيد ليحسم النظام النتيجة.';panel.appendChild(instruction);
 const board=document.createElement('div');board.className='qaid-history-board';
 history.forEach(trick=>{const row=document.createElement('section');row.className='qaid-trick-row';const label=document.createElement('div');label.className='qaid-trick-label';label.textContent='اللفة '+(trick.trickIndex+1);row.appendChild(label);const cards=document.createElement('div');cards.className='qaid-card-grid';
  trick.cards.forEach(item=>{const card=document.createElement('div');card.className='qaid-card-choice'+(item.violation?' qaid-violation-card':'');card.innerHTML='<span class="qaid-card-name">'+cardLabel(item.card)+'</span><span class="qaid-card-art">'+cardFace(item.card)+'</span><small>'+escapeHtml(item.playerName||('لاعب '+(item.seat+1)))+'</small>'+(item.violation?'<small class="qaid-proof-label qaid-proof-bad">ورقة مخالفة</small>'+(item.violationReason?'<small class="qaid-violation-reason">'+escapeHtml(item.violationReason)+'</small>':''):'');cards.appendChild(card);
   if(item.proofCard){const proof=document.createElement('div');proof.className='qaid-card-choice qaid-proof-card';proof.innerHTML='<span class="qaid-card-name">'+cardLabel(item.proofCard)+'</span><span class="qaid-card-art">'+cardFace(item.proofCard)+'</span><small>'+escapeHtml(item.playerName||('لاعب '+(item.seat+1)))+'</small><small class="qaid-proof-label qaid-proof-good">ورقة إثبات</small>';cards.appendChild(proof);}
  });row.appendChild(cards);board.appendChild(row);});
 if(!history.length){const empty=document.createElement('p');empty.textContent='لم تُلعب أوراق بعد.';board.appendChild(empty);}panel.appendChild(board);
 const actions=document.createElement('div');actions.className='qaid-actions';const summary=document.createElement('p');summary.className='qaid-selection-summary';summary.textContent=left>0?'لا يلزم تحديد الأوراق؛ الحكم آلي من سجل اللعب.':'انتهت المهلة؛ ستُحتسب النقاط للمنافس.';actions.appendChild(summary);
 const confirm=document.createElement('button');confirm.type='button';confirm.className='qaid-confirm';confirm.textContent='تأكيد القيد';confirm.disabled=left<=0||!!state.submitting;confirm.onclick=submitQaid;actions.appendChild(confirm);
 panel.appendChild(actions);container.appendChild(panel);const historyBoard=panel.querySelector('.qaid-history-board');if(historyBoard){historyBoard.scrollTop=scrollTop;requestAnimationFrame(()=>{historyBoard.scrollTop=scrollTop;});}document.body.classList.add('qaid-review-open');document.documentElement.classList.add('qaid-review-open');$('qaidPage').classList.remove('hidden');$('resultPage').classList.add('hidden');
 panel.querySelectorAll('.full-card-art').forEach(img=>{img.onerror=()=>{img.hidden=true;const fallback=img.nextElementSibling;if(fallback)fallback.hidden=false;};if(img.complete&&!img.naturalWidth)img.onerror();});
}
function cardLabel(c){const ranks={7:'7',8:'8',9:'9',10:'10',J:'ولد',Q:'بنت',K:'شايب',A:'آس'},suits={S:'♠',H:'♥',D:'♦',C:'♣'};return (ranks[c.rank]||c.rank)+' '+(suits[c.suit]||c.suit);}
function roundSummary(s){
 const awarded=s.lastAwarded||s.lastScoring?.awarded||[0,0],raw=s.lastScoring?.raw||s.lastRaw||[0,0],projects=s.lastScoring?.scoredProjectPoints||[0,0],scores=s.scores||[0,0],mine=s.mySeat%2===0?0:1,theirs=1-mine,landTeam=s.lastLandTeam,land=s.lastLandPoints||0;
 const result=s.qaidResult?(s.qaidResult.timeout?'انتهت مهلة القيد — خسر فاتحه':(s.qaidResult.success?'القيد صحيح':'القيد خطأ')+' — '+(s.qaidResult.winnerTeam===mine?'النقاط لنا':'النقاط لهم')):s.phase==='gameEnd'?'انتهت الصكة':s.lastScoring?.buyerSucceeded?'المشتري ناجح':'المشتري خسران';
 const projectList=team=>{const all=[...(s.lastScoring?.projects?.counted||[]),...(s.lastScoring?.projects?.belots||[])].filter(p=>p.team===team);const counts={};all.forEach(p=>{const label=p.label||p.type;counts[label]=(counts[label]||0)+1;});return Object.entries(counts).map(([label,count])=>label+(count>1?' ×'+count:'')).join('، ')||'—';};
 const title=s.phase==='gameEnd'?'انتهت الصكة':'نشرة التوزيعة';
 const team=(label,t)=>{const cardPoints=raw[t]-(landTeam===t?land:0);return '<section class="result-team"><strong>'+label+'</strong><div class="result-cell"><small>أبناط الورق</small><b>'+cardPoints+'</b></div><div class="result-cell"><small>الأرض</small><b>'+(landTeam===t?'+'+land:'—')+'</b></div><div class="result-cell"><small>المشاريع</small><b>'+projects[t]+'</b><em>'+escapeHtml(projectList(t))+'</em></div><div class="result-cell"><small>نقاط التوزيعة</small><b>'+awarded[t]+'</b></div><div class="result-cell result-total"><small>إجمالي الصكة</small><b>'+scores[t]+'</b></div></section>';};
 const actions='<div class="round-summary-actions">'+(s.phase==='roundEnd'?'<button type="button" class="round-summary-continue" data-continue>أكمل</button>':'<button type="button" class="round-summary-replay" data-replay>إعادة اللعب</button><a class="round-summary-home" href="/">القائمة الرئيسية</a><a class="round-summary-new-room" href="/create-room.html">غرفة جديدة</a><a class="round-summary-join-room" href="/join-room.html">دخول غرفة</a>')+(s.qaidEnabled&&!s.qaidResult?'<button type="button" class="round-summary-qaid" data-qaid-open>قيد</button>':'')+'</div>';
 return '<div class="round-summary" role="dialog" aria-modal="true" aria-label="'+title+'"><header><strong class="round-summary-title">'+title+'</strong><small>'+(s.phase==='roundEnd'?'تبدأ التوزيعة التالية تلقائياً بعد 10 ثوانٍ':'النتيجة النهائية للصكة')+'</small></header><div class="round-summary-result">'+result+'</div><div class="round-summary-grid">'+team('لنا',mine)+team('لهم',theirs)+'</div>'+actions+'</div>';
}
function playerLevel(profile){
 if(!profile||!Number(profile.matches))return '';
 const matches=Number(profile.matches),rate=Number(profile.wins||0)/matches;
 if(matches>=10&&rate>=.65)return 'ممتاز';
 if(matches>=5&&rate>=.5)return 'جيد';
 return 'مبتدئ';
}
function openPlayerProfile(player){
 let modal=$('playerProfileModal');
 if(!modal){modal=document.createElement('div');modal.id='playerProfileModal';modal.className='player-profile-modal hidden';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');document.body.appendChild(modal);}
 const profile=player.profile||null,name=player.bot?'كمبيوتر '+(player.botNumber||player.seat+10):(player.name||'لاعب'),level=playerLevel(profile);
 const stat=(value,label)=>'<div class="player-profile-stat"><b>'+Number(value||0).toLocaleString('ar-SA')+'</b><span>'+label+'</span></div>';
 modal.innerHTML='<section class="player-profile-card"><button type="button" class="player-profile-close" aria-label="إغلاق">×</button><div class="player-profile-avatar">'+escapeHtml(player.avatar||'👤')+'</div><h2>'+escapeHtml(name)+'</h2>'+(level?'<strong class="player-profile-level">'+escapeHtml(level)+'</strong>':'')+(profile?'<div class="player-profile-stats">'+stat(profile.matches,'مباراة')+stat(profile.wins,'فوز')+stat(profile.losses,'خسارة')+stat(profile.points,'النقاط')+'</div>':'<p class="player-profile-empty">'+(player.bot?'لا تنطبق إحصاءات الحساب على الكمبيوتر.':'لا توجد إحصاءات عامة لهذا اللاعب؛ قد يكون ضيفاً أو لم يكمل مباريات مسجلة.')+'</p>')+'</section>';
 modal.classList.remove('hidden');
 modal.querySelector('.player-profile-close').onclick=()=>modal.classList.add('hidden');
 modal.onclick=event=>{if(event.target===modal)modal.classList.add('hidden');};
}
function phaseDisplay(p,c){if(p==='playing'){const sun=c?.type==='sun';const mark=sun?'':' <span class="contract-icon '+(c?.trump==='D'?'diamond ':'')+('HD'.includes(c?.trump)?'red':'')+'">'+symbol(c?.trump)+'</span>';const escalation=c?.level>1?' · '+({2:'دبل',3:'ثري',4:'فور',5:'قهوة'}[c.level]||''):'';return (sun?'صن':'حكم')+mark+escalation;}return escapeHtml(phaseName(p,c));}
function phaseName(p,c){const escalation=c?.level>1?' · '+({2:'دبل',3:'ثري',4:'فور',5:'قهوة'}[c.level]||''):'';if(p==='lobby'||p==='dealing')return 'انتظار...';if(p==='projectDeclaration')return 'مشاريع';if(p==='postPurchase')return 'الرد على الحكم';if(p==='buyerConfirm')return 'تأكيد الحكم';if(p==='buyerSelectHukm')return 'اختيار نوع الحكم الثاني';if(p==='auction1'||p==='auction2')return 'شراء';if(p==='playing')return (c?.type==='sun'?'صن':'حكم '+symbol(c?.trump))+escalation;if(p==='roundEnd')return 'نهاية التوزيعة'+escalation;if(p==='gameEnd')return 'انتهت الصكة'+escalation;if(p==='redeal')return 'إعادة';return p;}
function cardFace(c){
 const rank=String(c.rank),suits={S:'spades',H:'hearts',D:'diamonds',C:'clubs'};
 const label=({7:'سبعة',8:'ثمانية',9:'تسعة',10:'عشرة',J:'ولد',Q:'بنت',K:'شايب',A:'آس'})[rank]||rank;
 const ranks={7:'7',8:'8',9:'9',10:'10',J:'jack',Q:'queen',K:'king',A:'ace'};
 const file=encodeURIComponent(ranks[rank]+' of '+suits[c.suit]+'.svg');
 const src='https://cdn.jsdelivr.net/gh/AustinGabriel/Public-Domain-and-CC0-Playing-Cards@main/svg%20cards/card%20fronts/'+suits[c.suit]+'/'+file;
 return '<img class="full-card-art" alt="'+label+' '+symbol(c.suit)+'" draggable="false" src="'+src+'"><span class="card-fallback" hidden><b>'+rank+'</b><i>'+symbol(c.suit)+'</i></span>';
}
function symbol(s){return ({S:'♠',H:'♥',D:'♦',C:'♣'})[s]||s}
function escapeHtml(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
async function loadBuildInfo(){
 try{const r=await fetch('/build.json',{cache:'no-store'});if(!r.ok)throw new Error('build info unavailable');const b=await r.json();$('buildVersion').textContent='نسخة '+b.version+' · Build '+b.commit;}
 catch{$('buildVersion').textContent='نسخة 0.3.0-preview · Build preview-2026-11-02.2 (واجهة)';}
}
$('timeoutReturn')?.addEventListener('click',()=>{ $('turnTimeout').classList.add('hidden');sendButtonAction($('timeoutReturn'),'room:timeout-return');});
$('timeoutBot')?.addEventListener('click',()=>{ $('turnTimeout').classList.add('hidden');sendButtonAction($('timeoutBot'),'room:timeout-bot');});

// Keep modal layers outside the scrolling table so touch/click targets are never clipped by table ancestors.
const resultLayer=$('resultPage'),qaidLayer=$('qaidPage');
if(resultLayer&&resultLayer.parentElement!==document.body)document.body.appendChild(resultLayer);
if(qaidLayer&&qaidLayer.parentElement!==document.body)document.body.appendChild(qaidLayer);

countdownTicker=setInterval(updateAvatarCountdowns,200);
loadBuildInfo();
restoreAccount();
document.addEventListener('pointerdown',e=>{if(selectedCardId&&!e.target.closest('#hand [data-card]')){selectedCardId=null;document.querySelectorAll('#hand [data-card].selected').forEach(card=>card.classList.remove('selected'));}});


