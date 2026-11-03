// Fixed table numbering: player 1 is bottom, 2 left, 3 top, 4 right.
// Visible counterclockwise path is 1 -> 4 -> 3 -> 2 -> 1.
const PLAYER_NUMBERS = Object.freeze([1, 2, 3, 4]);
const toPlayerNumber = seat => {
  if (!Number.isInteger(seat) || seat < 0 || seat > 3) throw new RangeError('Invalid seat');
  return seat + 1;
};
const toSeat = playerNumber => {
  if (!Number.isInteger(playerNumber) || playerNumber < 1 || playerNumber > 4) throw new RangeError('Invalid player number');
  return playerNumber - 1;
};
const clockwisePlayer = playerNumber => playerNumber === 4 ? 1 : playerNumber + 1;
const counterClockwisePlayer = playerNumber => playerNumber === 1 ? 4 : playerNumber - 1;
const clockwiseSeat = seat => toSeat(clockwisePlayer(toPlayerNumber(seat)));
const counterClockwiseSeat = seat => toSeat(counterClockwisePlayer(toPlayerNumber(seat)));
module.exports = { PLAYER_NUMBERS, toPlayerNumber, toSeat, clockwisePlayer, counterClockwisePlayer, clockwiseSeat, counterClockwiseSeat };
