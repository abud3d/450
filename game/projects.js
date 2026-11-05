const PROJECT_ORDER = ['A','K','Q','J','10','9','8','7'];
const SET_RANKS = ['A','K','Q','J','10'];
const teamOf = seat => seat % 2;

function detectPlayerProjects(hand, contract, seat) {
  const projects = [];
  const bySuit = {};
  for (const card of hand) (bySuit[card.suit] ||= []).push(card);
  for (const [suit, cards] of Object.entries(bySuit)) {
    const present = new Set(cards.map(c => c.rank));
    let run = [];
    for (const rank of PROJECT_ORDER) {
      if (present.has(rank)) run.push(rank);
      else { addRun(run, suit, projects, seat); run = []; }
    }
    addRun(run, suit, projects, seat);
  }
  for (const rank of SET_RANKS) {
    const cards = hand.filter(c => c.rank === rank);
    if (cards.length === 4) {
      if (rank === 'A' && contract.type === 'sun') {
        projects.push({ type:'400', rank:'A', strength:5, high:8, suit:null, seat, team:teamOf(seat), label:'أربعمائة' });
      } else {
        projects.push({ type:'100-set', rank, strength:3, high:PROJECT_ORDER.indexOf(rank), suit:null, seat, team:teamOf(seat), label:'مائة ورق' });
      }
    }
  }
  if (contract.type === 'hokum') {
    const hasK = hand.some(c => c.suit === contract.trump && c.rank === 'K');
    const hasQ = hand.some(c => c.suit === contract.trump && c.rank === 'Q');
    if (hasK && hasQ) projects.push({ type:'belot', strength:0, high:0, suit:contract.trump, seat, team:teamOf(seat), label:'بلوت', points:2 });
  }
  return dedupeProjects(projects).map(project => ({...project, cards: project.type === '400' || project.type === '100-set' ? hand.filter(c => c.rank === project.rank) : project.suit ? hand.filter(c => c.suit === project.suit && PROJECT_ORDER.slice(project.high, project.high + (project.length || 0)).includes(c.rank)) : []}));
}

function addRun(run, suit, out, seat) {
  if (run.length < 3) return;
  const length = run.length;
  const type = length >= 5 ? '100-run' : length === 4 ? '50' : '20';
  const strength = length >= 5 ? 4 : length === 4 ? 2 : 1;
  const high = Math.min(...run.map(r => PROJECT_ORDER.indexOf(r)));
  out.push({ type, strength, high, suit, seat, team:teamOf(seat), label:length >= 5 ? 'مائة متسلسلة' : length === 4 ? 'خمسين' : 'سرا', length });
}
function dedupeProjects(projects) {
  const belot = projects.find(p => p.type === 'belot');
  const regular = projects.filter(p => p.type !== 'belot').sort((a,b)=>compareProject(b,a))[0];
  return [regular, belot].filter(Boolean);
}
function compareProject(a,b) {
  return (a.strength-b.strength) || (a.type==='100-set' && b.type==='100-set' ? b.high-a.high : 0);
}
function projectValue(project, contractType) {
  if (project.type === 'belot') return 2;
  if (contractType === 'sun') return ({'20':4,'50':10,'100-run':20,'100-set':20,'400':40})[project.type] || 0;
  return ({'20':2,'50':5,'100-run':10,'100-set':10})[project.type] || 0;
}
function resolveProjects(playerProjects, dealer, contractType) {
  const entries = playerProjects.flat().filter(p => p.type !== 'belot');
  const belots = playerProjects.flat().filter(p => p.type === 'belot');
  const regularPoints=[0,0], belotPoints=[0,0];
  if (!entries.length) {
    for(const p of belots) belotPoints[p.team]+=2;
    return { winnerTeam:null, winner:null, counted:[], belots, regularPoints, belotPoints, points:[...belotPoints] };
  }
  const ordered = [...entries].sort((a,b) => {
    const c = compareProject(b,a);
    if (c) return c;
    const da = (a.seat - ((dealer+1)%4) + 4) % 4;
    const db = (b.seat - ((dealer+1)%4) + 4) % 4;
    return da-db;
  });
  const winner = ordered[0], winnerTeam = winner.team;
  const counted = entries.filter(p => p.team === winnerTeam);
  for (const p of counted) regularPoints[winnerTeam] += projectValue(p,contractType);
  for (const p of belots) belotPoints[p.team] += 2;
  const points=regularPoints.map((n,i)=>n+belotPoints[i]);
  return { winnerTeam, winner, counted, belots, regularPoints, belotPoints, points };
}
module.exports = { PROJECT_ORDER, detectPlayerProjects, compareProject, projectValue, resolveProjects };
