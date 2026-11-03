const SUITS = ['S','H','D','C'];
const RANKS = ['7','8','9','J','Q','K','10','A'];
const SUN_ORDER = ['7','8','9','J','Q','K','10','A'];
const TRUMP_ORDER = ['7','8','Q','K','10','A','9','J'];
const SUN_POINTS = {A:11,'10':10,K:4,Q:3,J:2,9:0,8:0,7:0};
const TRUMP_POINTS = {J:20,9:14,A:11,'10':10,K:4,Q:3,8:0,7:0};
const SUIT_NAMES = {S:'♠',H:'♥',D:'♦',C:'♣'};
const teamOf = seat => seat % 2;
const makeDeck = () => SUITS.flatMap(suit => RANKS.map(rank => ({suit,rank,id:suit+rank})));
const shuffle = cards => { const a=[...cards]; for(let i=a.length-1;i>0;i--){const j=require('crypto').randomInt(i+1);[a[i],a[j]]=[a[j],a[i]];} return a; };
const rankValue = (card, trump) => (trump && card.suit===trump ? TRUMP_ORDER : SUN_ORDER).indexOf(card.rank);
const cardPoints = (card, contract) => (contract.type==='hokum' && card.suit===contract.trump ? TRUMP_POINTS[card.rank] : SUN_POINTS[card.rank]);
function winnerOf(trick, contract) {
  const lead=trick[0].card.suit;
  let best=trick[0];
  for(const play of trick.slice(1)){
    const a=play.card,b=best.card;
    const aTrump=contract.type==='hokum'&&a.suit===contract.trump;
    const bTrump=contract.type==='hokum'&&b.suit===contract.trump;
    if(aTrump&&!bTrump) best=play;
    else if(aTrump===bTrump){
      if(aTrump){if(rankValue(a,contract.trump)>rankValue(b,contract.trump))best=play;}
      else if(a.suit===lead&&b.suit!==lead)best=play;
      else if(a.suit===lead&&b.suit===lead&&rankValue(a,null)>rankValue(b,null))best=play;
    }
  }
  return best.seat;
}
function legalCards(hand,trick,contract,seat){
  if(!trick.length)return hand;
  const lead=trick[0].card.suit, following=hand.filter(c=>c.suit===lead);
  if(contract.type==='sun') return following.length?following:hand;
  if(following.length)return following;
  const trumps=hand.filter(c=>c.suit===contract.trump);
  if(!trumps.length)return hand;
  const currentWinner=winnerOf(trick,contract);
  const partnerWinning=Number.isInteger(seat)&&teamOf(currentWinner)===teamOf(seat);
  if(partnerWinning)return hand;
  const tableTrumps=trick.filter(p=>p.card.suit===contract.trump);
  if(tableTrumps.length){
    const highest=Math.max(...tableTrumps.map(p=>rankValue(p.card,contract.trump)));
    const over=trumps.filter(c=>rankValue(c,contract.trump)>highest);
    if(over.length)return over;
  }
  return trumps;
}
function createGame(names=['ضيف','ضيف','ضيف','ضيف']){
  return {phase:'dealing',players:names.map((name,seat)=>({name,seat,bot:false})),hands:[[],[],[],[]],scores:[0,0],dealScores:[],dealer:0,turn:0,round:1,upCard:null,contract:null,trick:[],tricks:[],tricksWon:[0,0],history:[],lastWinner:null,auctionPasses:0};
}
function deal(game){
  const deck=shuffle(makeDeck());
  game.hands=Array.from({length:4},(_,i)=>deck.slice(i*5,(i+1)*5));
  game.upCard=deck[20]; game.stock=deck.slice(21);
  game.phase='auction1'; game.turn=(game.dealer+1)%4; game.auctionPasses=0;
  game.contract=null;game.trick=[];game.tricks=[];game.tricksWon=[0,0];game.history=[];
  return game;
}
function chooseContract(game,seat,choice){
  if(!['auction1','auction2'].includes(game.phase)||game.turn!==seat)throw Error('ليس دورك في الشراء');
  if(game.phase==='auction1'){
    if(choice==='take')game.contract={type:'hokum',trump:game.upCard.suit,buyer:seat};
    else if(choice!=='pass')throw Error('اختيار شراء غير صالح');
    if(game.contract) finishDeal(game);
    else {game.auctionPasses++;game.turn=(seat+1)%4;if(game.auctionPasses>=4){game.phase='auction2';game.turn=(game.dealer+1)%4;game.auctionPasses=0;}}
  } else {
    if(choice==='sun')game.contract={type:'sun',trump:null,buyer:seat};
    else if(['S','H','D','C'].includes(choice)&&choice!==game.upCard.suit)game.contract={type:'hokum',trump:choice,buyer:seat};
    else if(choice!=='pass')throw Error('اختيار شراء غير صالح');
    if(game.contract){game.contract.buyer=seat;finishDeal(game);}
    else {game.auctionPasses++;game.turn=(seat+1)%4;if(game.auctionPasses>=4)game.phase='redeal';}
  }
  return game;
}
function finishDeal(game){
  const buyer=game.contract.buyer;
  game.hands[buyer].push(game.upCard);
  const remaining=game.stock;
  let k=0;
  for(let n=0;n<4;n++){const seat=(game.dealer+1+n)%4;while(game.hands[seat].length<8)game.hands[seat].push(remaining[k++]);}
  game.hands.forEach(h=>h.sort((a,b)=>SUITS.indexOf(a.suit)-SUITS.indexOf(b.suit)||RANKS.indexOf(a.rank)-RANKS.indexOf(b.rank)));
  game.phase='playing';game.turn=(game.dealer+1)%4;
}
function playCard(game,seat,cardId){
  if(game.phase!=='playing'||game.turn!==seat)throw Error('ليس دورك في اللعب');
  const hand=game.hands[seat],card=hand.find(c=>c.id===cardId);
  if(!card)throw Error('الورقة غير موجودة في يدك');
  const allowed=legalCards(hand,game.trick,game.contract,seat);
  if(!allowed.some(c=>c.id===cardId))throw Error('هذه الورقة مخالفة لقواعد اللعب');
  hand.splice(hand.findIndex(c=>c.id===cardId),1);
  game.trick.push({seat,card});game.history.push({seat,card});
  if(game.trick.length<4){game.turn=(seat+1)%4;return game;}
  const winningSeat=winnerOf(game.trick,game.contract);
  const points=game.trick.reduce((sum,p)=>sum+cardPoints(p.card,game.contract),0);
  game.tricksWon[teamOf(winningSeat)]++;
  game.tricks.push({plays:game.trick,winner:winningSeat,team:teamOf(winningSeat),points});
  game.lastWinner=winningSeat;game.trick=[];game.turn=winningSeat;
  if(game.hands.every(h=>h.length===0))finishRound(game);
  return game;
}
function finishRound(game){
  const raw=[0,0];
  for(const t of game.tricks)raw[t.team]+=t.points;
  raw[teamOf(game.lastWinner)]+=10;
  // Card-point totals are retained for audit; scoreboard uses the conventional 26 Sun / 16 Hokum deal scale. Project bonuses are not yet applied.
  game.lastRaw=raw;
  const roundTotal=game.contract.type==='sun'?26:16;
  const totalRaw=raw[0]+raw[1];
  const buyerTeam=teamOf(game.contract.buyer);
  const buyerWon=raw[buyerTeam]>raw[1-buyerTeam];
  let awarded;
  if(!buyerWon){awarded=[0,0];awarded[1-buyerTeam]=roundTotal;}
  else {const points0=totalRaw?Math.round(raw[0]/totalRaw*roundTotal):0;awarded=[points0,roundTotal-points0];}
  game.lastAwarded=awarded;game.phase='roundEnd';
  game.dealScores.push({round:game.round,contract:{...game.contract},raw:[...raw],awarded:[...awarded],tricksWon:[...game.tricksWon]});
  game.scores[0]+=awarded[0];game.scores[1]+=awarded[1];
  if(game.scores[0]>=152||game.scores[1]>=152){game.phase='gameEnd';game.winner=game.scores[0]===game.scores[1]?'tie':game.scores[0]>game.scores[1]?0:1;}
}
function nextRound(game){
  if(!['roundEnd','redeal'].includes(game.phase))throw Error('لا يمكن بدء توزيعة جديدة الآن');
  game.dealer=(game.dealer+1)%4;game.round++;
  if(game.phase==='redeal')game.scores=game.scores; 
  return deal(game);
}
function botBid(game,seat){
  const hand=game.hands[seat];
  const suit=game.upCard.suit;
  const trump=hand.filter(c=>c.suit===suit);
  const strength=trump.reduce((s,c)=>s+({J:6,9:4,A:2,'10':1,K:1,Q:0.5}[c.rank]||0),0);
  if(game.phase==='auction1')return strength>=5?'take':'pass';
  const suits=SUITS.filter(s=>s!==game.upCard.suit);
  let best={type:'pass',value:0};
  for(const s of suits){const v=hand.filter(c=>c.suit===s).reduce((a,c)=>a+({A:2,'10':1.4,K:0.7,Q:0.4,J:0.5}[c.rank]||0),0);if(v>best.value)best={type:s,value:v};}
  const sun=hand.reduce((a,c)=>a+({A:1.4,'10':1.1,K:0.5,Q:0.3}[c.rank]||0),0);
  if(sun>best.value)best={type:'sun',value:sun};
  return best.value>=5?best.type:'pass';
}
function botPlay(game,seat){
  const hand=game.hands[seat],legal=legalCards(hand,game.trick,game.contract,seat);
  if(legal.length===1)return legal[0].id;
  const partnerWinning=game.trick.length&&teamOf(winnerOf(game.trick,game.contract))===teamOf(seat);
  const pointsOnTable=game.trick.reduce((s,p)=>s+cardPoints(p.card,game.contract),0);
  const ranked=[...legal].sort((a,b)=>{
    const score=c=>{
      const trial=[...game.trick,{seat,card:c}],wins=winnerOf(trial,game.contract)===seat;
      const trump=game.contract.type==='hokum'&&c.suit===game.contract.trump;
      return (wins?(partnerWinning?-20:pointsOnTable+cardPoints(c,game.contract)+8):0)+(trump?2:0)+rankValue(c,game.contract.trump)*0.1;
    };
    return score(a)-score(b);
  });
  return ranked[0].id;
}
module.exports={SUITS,RANKS,makeDeck,shuffle,rankValue,cardPoints,winnerOf,legalCards,createGame,deal,chooseContract,playCard,nextRound,botBid,botPlay,finishRound,teamOf};
